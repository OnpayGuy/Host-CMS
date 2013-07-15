<?php
/*
#####################################
#  OnPay payment module for HostCMS.
#  Copyright (c) 2011 by norgen
#  http://www.onpay.ru
#  http://www.hostcms.ru
#  Ver. 1.0.0
#####################################
*/

/* Оплата через OnPay */

class system_of_pay_handler {

// Идентифкатор пользователя в системе OnPay
    var $onpay_userId = 'norgen';
// Секретный ключ (заполняется вручную пользователем на странице настроек магазина). Содержит больше 10 знаков!
    var $secretKey = "1231233211";
// Валюта платежа RUR для работы или TST для тестирования системы
    var $currencyName = "RUR";
//  var $currencyName = "TST";
// Код валюты в магазине HostCMS, которая была указана при регистрации магазина
    var $intellectmoney_currency = 1;

    function answer($type, $code, $pay_for, $order_amount, $order_currency, $text) {
        $key = $this->secretKey;
        $md5 = strtoupper(md5("$type;$pay_for;$order_amount;$order_currency;$code;$key"));
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>\n<code>$code</code>\n<pay_for>$pay_for</pay_for>\n<comment>$text</comment>\n<md5>$md5</md5>\n</result>";
    }

//Функция выдает ответ для сервиса onpay в формате XML на pay запрос
    function answerpay($type, $code, $pay_for, $order_amount, $order_currency, $text, $onpay_id) {
        $key = $this->secretKey;
        $md5 = strtoupper(md5("$type;$pay_for;$onpay_id;$pay_for;$order_amount;$order_currency;$code;$key"));
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>\n<code>$code</code>\n <comment>$text</comment>\n<onpay_id>$onpay_id</onpay_id>\n <pay_for>$pay_for</pay_for>\n<order_id>$pay_for</order_id>\n<md5>$md5</md5>\n</result>";
    }

// Запускаем обработчик платежной системы
    function Execute() {
// Пришло подтверждение оплаты, обработаем его
        if (isset($_REQUEST['pay_for'])) {
            $this->ProcessResult();
            return true;
        }

// Иначе оформляем заказ и отображаем стартовую страницу для оплаты через IntellectMoney
        $this->ShowPurseRequest();
    }

// Обработка ответа от OnPay
    function ProcessResult() {

// Функция выдает ответ для сервиса onpay в формате XML на чек запрос
// Определяем и отрабатываем запрос по типу

        if (to_str($_REQUEST['type']) == 'check') {
            $rezult = 'Very bad!';
            $order_amount = $_REQUEST['order_amount'];
            $order_currency = $_REQUEST['order_currency'];
            $pay_for = $_REQUEST['pay_for'];
            $md5 = $_REQUEST['md5'];
            $sum = floatval($order_amount);
            $shop = & singleton('shop');

            $order_row = $shop->GetOrder($pay_for);

            if (!$order_row || $order_row['shop_order_status_of_pay']) { // Заказ не найден или уже оплачен
                $rezult = $this->answer($_REQUEST['type'], 2, $pay_for, $order_amount, $order_currency, 'Error code pay_for: ' . $pay_for); //Сообщаем ошибку
            } else
                $rezult = $this->answer($_REQUEST['type'], 0, $pay_for, $order_amount, $order_currency, 'OK'); //Отвечаем серверу OnPay, что все хорошо, можно принимать деньги
        }


        if (to_str($_REQUEST['type']) == 'pay') {
            $rezult = "Bad pay;";
            $onpay_id = $_REQUEST['onpay_id'];
            $pay_for = $_REQUEST['pay_for'];
            $order_amount = $_REQUEST['order_amount'];
            $order_currency = $_REQUEST['order_currency'];
            $balance_amount = $_REQUEST['balance_amount'];
            $balance_currency = $_REQUEST['balance_currency'];
            $exchange_rate = $_REQUEST['exchange_rate'];
            $paymentDateTime = $_REQUEST['paymentDateTime'];
            $md5 = $_REQUEST['md5'];
            $key = $this->secretKey;

            $shop = & singleton('shop');
            $order_row = $shop->GetOrder($pay_for);
            $shop_row = $shop->GetShop($order_row['shop_shops_id']);
            $order_sum = $shop->GetOrderSum($pay_for);
            //$pay_for = intval($pay_for);
            $md5fb = strtoupper(md5($_REQUEST['type'] . ";" . $pay_for . ";" . $onpay_id . ";" . $order_amount . ";" . $order_currency . ";" . $key . "")); //Создаем строку хэша с присланных данных

            if ($md5fb != $md5 || ($order_amount != $order_sum)) { // Если совпадает хэш и оплаченная сумма - проводим заказ
                $rezult = $this->answerpay($_REQUEST['type'], 7, $pay_for, $order_amount, $order_currency, 'Md5 signature is wrong or price mismatch', $onpay_id);
            }
            else {
                $rezult = $this->answerpay($_REQUEST['type'], 0, $pay_for, $order_amount, $order_currency, 'OK', $onpay_id);
                // Устанавливаем параметры, которые не зависят от совпадения хешей

                $param['system_information'] = "Товар оплачен через OnPay.\n\n"
                        . "Информация:\n\n"
                        . "Номер покупки (СКО): {$_REQUEST['pay_for']}\n"
                        . "Сумма платежа: {$_REQUEST['order_amount']}\n"
                        . "Номер транзакции в системе OnPay: {$_REQUEST['onpay_id']}\n"
                        . "\n";

// Обновляем информацию о заказе

                $shop->InsertOrder($param);

// Изменяем статус оплаты ПОСЛЕ ОБНОВЛЕНИЯ ИНФОРМАЦИ, генерируем ссылки для эл.товаров, списываем товары
                $shop->SetOrderPaymentStatus($pay_for);

                $structure = & singleton('Structure');
                $structure_row = $structure->GetStructureItem(to_int($shop_row['structure_id']));

                $lib = new lib();
                $LA = $lib->LoadLibPropertiesValue(to_int($structure_row['lib_id']), to_int($structure_row['structure_id']));

                $order_row = $shop->GetOrder($pay_for);

// Отправляем письмо администратору о подтверждении платежа
                $shop->SendMailAboutOrder($order_row['shop_shops_id'], $pay_for, $order_row['site_users_id'], to_str($LA['xsl_letter_to_admin']), to_str($LA['xsl_letter_to_user']), $order_row['shop_order_users_email'], array(
                    'admin-content-type' => 'html',
                    'user-content-type' => 'html',
                    'admin-subject' => sprintf($GLOBALS['MSG_shops']['shop_order_confirm_admin_subject'], $pay_for, $shop_row['shop_shops_name'], $order_row['shop_order_date_of_pay']),
                    'user-subject' => sprintf($GLOBALS['MSG_shops']['shop_order_confirm_user_subject'], $pay_for, $shop_row['shop_shops_name'], $order_row['shop_order_date_of_pay']),
                    'email_from_admin' => $order_row['shop_order_users_email']));
            }
        }
        print $rezult;
        return;
    }

    /**
     * Отображает стартовую страницу для оплаты через OnPay.
     *
     */
    function ShowPurseRequest() {
        $shop = & singleton('shop');

// ID платежной системы берем из сессии 
        $system_of_pay_id = to_int($_SESSION['system_of_pay_id']);

        $row_system_of_pay = $shop->GetSystemOfPay($system_of_pay_id);

        if ($row_system_of_pay) {
            $shop_id = $row_system_of_pay['shop_shops_id'];
        }
        else {
            return false;
        }

// Получаем id текущего пользователя сайта
        if (class_exists('SiteUsers')) {
// Получаем id текущего пользователя сайта
            $SiteUsers = & singleton('SiteUsers');
            $site_users_id = $SiteUsers->GetCurrentSiteUser();
        }
        else {
            $site_users_id = false;
        }

// статус платежа, по умолчанию 0
        $order_row['status_of_pay'] = 0;

// дата платежа, по умолчанию пустая строка
        $order_row['date_of_pay'] = '';

        $order_row['description'] = to_str($_SESSION['description']);

// описание и системная информация, по умолчанию пустая строка
        if (to_str($_SESSION['shop_coupon_text']) != '') {
            $order_row['description'] .= "Купон на скидку: " . to_str($_SESSION['shop_coupon_text']) . "\n";
        }

        if (!isset($_SESSION['last_order_id'])) {
            $_SESSION['last_order_id'] = 0;
        }

// Если заказ еще не был оформлен
        if ($_SESSION['last_order_id'] == 0) {
// Оформляем заказ
            $order_id = $shop->ProcessOrder($shop_id, $site_users_id, $system_of_pay_id, $order_row);
        }
        else {
            $order_id = $_SESSION['last_order_id'];
        }

        if ($order_id > 0) {
            if (!class_exists('SiteUsers')) {
// Класс пользователей сайта не существует, дописываем информацию о заказчике в поле shop_order_description из текущей сессии
                if ($order_row) {
// Описание заказчика 
                    $order_row['description'] .= "Информация о заказчике:\n"
                            . "Имя: " . to_str($_SESSION['site_users_name']) . "\n"
                            . "Фамилия: " . to_str($_SESSION['site_users_surname']) . "\n"
                            . "Отчество: " . to_str($_SESSION['site_users_patronymic']) . "\n"
                            . "E-Mail: " . to_str($_SESSION['site_users_email']) . "\n"
                            . "Телефон: " . to_str($_SESSION['site_users_phone']) . "\n"
                            . "Факс: " . to_str($_SESSION['site_users_fax']) . "\n"
                            . "Адрес: " . to_str($_SESSION['full_address']) . "\n";

// Дополнительная информация о заказе 
                    $order_row['system_information'] = to_str($_SESSION['system_information']);

// Обязательно добавляем идентификатор!
                    $order_row['id'] = $order_id;

                    $shop->InsertOrder($order_row);
                }
            }

            $order_row = $shop->GetOrder($order_id);

            if ($order_row) {
                $this->PrintOrder($order_id);
            }

            $shop_row = $shop->GetShop($shop_id);

            if ($_SESSION['last_order_id'] == 0) {
                $structure = & singleton('Structure');
                $structure_row = $structure->GetStructureItem(to_int($shop_row['structure_id']));

                $lib = new lib();
                $LA = $lib->LoadLibPropertiesValue(to_int($structure_row['lib_id']), to_int($structure_row['structure_id']));

                $date_str = date("d.m.Y H:i:s");

                if (trim(to_str($order_row['shop_order_account_number'])) != '') {
                    $shop_order_account_number = trim(to_str($order_row['shop_order_account_number']));
                }
                else {
                    $shop_order_account_number = $order_id;
                }

// Отправляем письмо заказчику 
                $shop->SendMailAboutOrder($shop_id, $order_id, $site_users_id, to_str($LA['xsl_letter_to_admin']), to_str($LA['xsl_letter_to_user']), $order_row['shop_order_users_email'], array('admin-content-type' => 'html',
                    'user-content-type' => 'html',
                    'admin-subject' => sprintf($GLOBALS['MSG_shops']['shop_order_admin_subject'], $shop_order_account_number, $shop_row['shop_shops_name'], $date_str),
                    'user-subject' => sprintf($GLOBALS['MSG_shops']['shop_order_user_subject'], $shop_order_account_number, $shop_row['shop_shops_name'], $date_str),
                    'email_from_admin' => $order_row['shop_order_users_email']));
            }

// Сохраняем ID последнего оформленного заказа ТОЛЬКО ПОСЛЕ ОТПРАВКИ ПИСЬМА
            $_SESSION['last_order_id'] = $order_id;
        }
        else {
            switch ($order_id) {
                case -1: {
                        ?><div id="error">Ошибка вставки заказа в базу данных. Обратитесь к администратору.</div><?php
                        break;
                    }
                case -2: {
                        ?><div id="error">Ошибка - не найден магазин. Обратитесь к администратору.</div><?php
                        break;
                    }
                case -3: {
                        ?><div id="error">Ошибка - корзина пуста. Добавьте товар в корзину и оформите заказ.</div><?php
                        break;
                    }
            }
        }
    }

    /**
     * Метод для отображения формы заказа для печати.
     *
     * @param int $order_id идентификатор заказа
     */
    function PrintOrder($order_id) {
        $shop = & singleton('shop');

        $order_row = $shop->GetOrder($order_id);

        if (!$order_row) {
            return false;
        }

        if ($order_row) {
            $shop_row = $shop->GetShop($order_row['shop_shops_id']);

            $order_id = $order_row['shop_order_id'];

            $order_sum = $shop->GetOrderSum($order_id);

// Делаем перерасчет суммы в валюту IntellectMoney 
            $shop_currency_id = $shop_row['shop_currency_id'];

// Получаем название валюты
            $currency_row = $shop->GetCurrency($shop_currency_id);

            $currency_name = $currency_row['shop_currency_name'];

            $coefficient = $shop->GetCurrencyCoefficientToShopCurrency($shop_currency_id, $this->intellectmoney_currency);

            $im_sum = round($order_sum * $coefficient, 2);

// Получаем имя/фамилию/отчество заказчика
//$fio = implode(" ", array($order_row['shop_order_users_surname'], $order_row['shop_order_users_name'], $order_row['shop_order_users_patronymic']));
// Получаем email заказчика
            $email = $order_row['shop_order_users_email'];

// Информация об алиасе сайта
            $site = & singleton('site');
            $site_alias = $site->GetCurrentAlias($shop_row['site_id']);

            /* Получаем путь к магазину */
            $Structure = & singleton('Structure');
            $shop_path = "/" . $Structure->GetStructurePath($shop_row['structure_id'], 0);

            $handler_url = 'http://' . $site_alias . $shop_path . 'cart/';

            $sum_for_md5 = $im_sum . '.0';  // В магазине круглые цены, без копеек.
            //print ($handler_url);

            $md5check = md5("fix;$sum_for_md5;$this->currencyName;$order_id;yes;$this->secretKey");
            ?>
            <h1>Оплата через систему OnPay</h1>
            <!-- Форма для оплаты через OnPay -->
            <p>К оплате <strong><?php echo $im_sum . " " . $currency_name ?></strong></p>
            <form id="pay" name="pay" method="post" action="<?php echo 'http://secure.onpay.ru/pay/' . htmlspecialchars($this->onpay_userId) . '/' ?>">
                <input id="pay_for" type="hidden" name="pay_for" value="<?php echo htmlspecialchars($order_id) ?>">
                <input id="pay_mode" type="hidden" name="pay_mode" value="fix">
                <input id="price" type="hidden" name="price" value="<?php echo htmlspecialchars($im_sum) ?>">
                <input id="currency" type="hidden" name="currency" value="<?php echo $this->currencyName ?>">
                <input id="successUrl" type="hidden" name="url_success" value="<?php echo htmlspecialchars($handler_url) ?>">
                <input id="convert" type="hidden" name="convert" value="yes">
                <input id="md5" type="hidden" name="md5" value="<?php echo htmlspecialchars($md5check) ?>">
                <input id="Email" type="hidden" name="user_email" value="<?php echo htmlspecialchars($email) ?>">
                <input name="submit" value="Перейти к оплате" type="submit"/>
            </form>
            <?php
        }
    }

    /**
     * Изменение статуса заказа. Позволяет пользователю внедрять собственные
     * обработчики при изменении статуса.
     *
     * @param array $param массив атрибутов
     * - $param['shop_order_id'] идентификатор заказа
     * - $param['prev_order_row'] информация о предыдущем состоянии заказа (доступно не всегда)
     * - $param['action'] выполняемое действие над заказом, может принимать значения:
     * edit (редактирование заказа), cancel (отмена заказа),
     * status (изменение статуса заказа), delete (удаление заказа),
     * edit_item (редактирование товара в заказе), delete_item (удаление товара в заказе)
     */
    function ChangeStatus($param = array()) {
// Если произошло изменение статуса
        if (isset($param['action']) && in_array($param['action'], array('status', 'edit'))) {
            $shop_order_id = to_int($param['shop_order_id']);

            $shop = & singleton('shop');

            $order_row = $shop->GetOrder($shop_order_id);

// Получаем информацию о магазине
            $shop_id = to_int($order_row['shop_shops_id']);

            $shop_row = $shop->GetShop($shop_id);

            $structure = & singleton('Structure');
            $structure_row = $structure->GetStructureItem(to_int($shop_row['structure_id']));

            $lib = new lib();
            $LA = $lib->LoadLibPropertiesValue(to_int($structure_row['lib_id']), to_int($structure_row['structure_id']));

            if ($order_row) {
                $DateClass = new DateClass();
                $date_str = $DateClass->datetime_format($order_row['shop_order_date_time']);
            }
            else {
                $date_str = '';
            }

// Если предыдущий статус заказа был 1, то меняем тему на подтверждение
            if (to_int($order_row['shop_order_status_of_pay']) == 1) {
                $admin_subject = $GLOBALS['MSG_shops']['shop_order_confirm_admin_subject'];
                $user_subject = $GLOBALS['MSG_shops']['shop_order_confirm_user_subject'];
            }
            else {
                $admin_subject = $GLOBALS['MSG_shops']['shop_order_admin_subject'];
                $user_subject = $GLOBALS['MSG_shops']['shop_order_user_subject'];
            }

            $not_paid = isset($param['prev_order_row']) && $param['prev_order_row']['shop_order_status_of_pay'] == 0;

// Письмо отправляем только при установке статуса активности для заказа
            if (to_int($order_row['shop_order_status_of_pay']) == 1 && $not_paid) {
                if (trim(to_str($order_row['shop_order_account_number'])) != '') {
                    $shop_order_account_number = trim(to_str($order_row['shop_order_account_number']));
                }
                else {
                    $shop_order_account_number = $shop_order_id;
                }

                /* Отправляем письмо заказчику */
                $shop->SendMailAboutOrder($shop_id, $shop_order_id, $order_row['site_users_id'], to_str($LA['xsl_letter_to_admin']), to_str($LA['xsl_letter_to_user']), $order_row['shop_order_users_email'], array('admin-content-type' => 'html',
                    'user-content-type' => 'html',
                    'admin-subject' => sprintf($admin_subject, $shop_order_account_number, $shop_row['shop_shops_name'], $date_str),
                    'user-subject' => sprintf($user_subject, $shop_order_account_number, $shop_row['shop_shops_name'], $date_str),
                    'email_from_admin' => $order_row['shop_order_users_email']));
            }
        }
    }

}
?>
