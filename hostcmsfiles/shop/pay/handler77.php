<?php

/**
 * Paymaster платежная система (paymaster.ru)
 * С поддержкой онлайн кассы по ФЗ-54
 * Поддерживает и принимает электронные деньги, банковские карты, денежные переводы, оплата в терминалах самообслуживания,
 * оплата со счета мобильного и другие способы, а также вести учет операций и управлять транзакциями в личном кабинете.
 *
 * Поддержка обработчика платежной системы осуществляется Cash24.
 * https://info.paymaster.ru/
 *
 */
class Shop_Payment_System_Handler77 extends Shop_Payment_System_Handler
{
    /**
     * Номер продавца (merchant) в системе Paymaster
     */
    private $_merchant_id = '';

    /**
     * Секретный ключ продавца (устанавливается в личном кабинете) продавца Paymaster
     */
    private $_secret_key = '';


    /**
     * Метод шифромания: md5 - md5, sha1 - sha1, sha256 - sha256
     */
    private $_signature_method = 'sha256';


    /**
     * Валюта по умолчанию
     */
    private $_default_currency_id = 1;


    /**
     * Метод, запускающий выполнение обработчика
     */
    public function execute()
    {
        parent::execute();

        $this->printNotification();

        return $this;
    }

    /**
     * Оформление нового заказа
     */
    protected function _processOrder()
    {
        parent::_processOrder();

        $this->setXSLs();

        $this->send();

        return $this;
    }

    /**
     * Сумма заказа в валюте платежной системы ($this->_currency)
     */
    public function getSumWithCoeff()
    {
        return Shop_Controller::instance()->round(($this->_default_currency_id > 0
            && $this->_shopOrder->shop_currency_id > 0
                ? Shop_Controller::instance()->getCurrencyCoefficientInShopCurrency(
                    $this->_shopOrder->Shop_Currency,
                    Core_Entity::factory('Shop_Currency', $this->_default_currency_id)
                )
                : 0) * $this->_shopOrder->getAmount());
    }

    /**
     * Обработка уведомлений об оплате заказа или возврате пользователя из магазина.
     * Используются уникальные параметры, передаваемые платежной системой и позволяющие
     * определить, идет уведомление об платежной системы об оплате или возврат пользователя
     * из платежной системы на сайт после успешной/неуспешной оплаты.
     */
    public function paymentProcessing()
    {
        if (isset($_GET['payment'])) {
            if ($_GET['payment'] == 'success' || $_GET['payment'] == 'fail') {
                $this->ShowResultMessage();
                return true;
            }
        }
        $this->ProcessResult();
        return true;
    }


    /**
     * Вывод сообщения об успешности/неуспешности оплаты
     */
    public function ShowResultMessage()
    {

    }

    /**
     * Обработка статуса оплаты
     */
    function ProcessResult()
    {
        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            if (isset($_POST["LMI_PREREQUEST"]) && ($_POST["LMI_PREREQUEST"] == "1" || $_POST["LMI_PREREQUEST"] == "2")) {
                echo "YES";
                die;
            } else {
                $oShop_Order = Core_Entity::factory('Shop_Order')->find(Core_Array::getRequest('LMI_PAYMENT_NO', 0));

                if (is_null($oShop_Order->id) || $oShop_Order->paid) {
                    die;
                }

                $hash = $this->_getHash($_POST);

                if ($_POST["LMI_HASH"] == $hash && $_POST["SIGN"] == $this->_getSign($_POST)) {
                    $this->shopOrder($oShop_Order)->shopOrderBeforeAction(clone $oShop_Order);
                    $oShop_Order->system_information = "Товар оплачен через Paymaster.\n";
                    $oShop_Order->paid();
                    $this->setXSLs();
                    $this->send();
                    $this->changedOrder('changeStatusPaid');
                }
            }
        }
        die;
    }


    /**
     * Возвращает строку с формой перехода к оплате
     */
    public function getNotification()
    {
        $oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
        $site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
        $shop_path = $this->_shopOrder->Shop->Structure->getPath();
        $handler_url = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http') . '://' . $site_alias . $shop_path . 'cart/';

        $oShop_Currency = Core_Entity::factory('Shop_Currency', $this->_default_currency_id);

        // переменная для формирования подписи
        $data = array(
            'LMI_MERCHANT_ID' => $this->_merchant_id,
            'LMI_PAYMENT_NO' => $this->_shopOrder->id,
            'LMI_PAID_AMOUNT' => $this->getSumWithCoeff(),
            'LMI_PAID_CURRENCY' => $oShop_Currency->code
        );

        $fields = array(
            'LMI_PAYMENT_AMOUNT' => $this->getSumWithCoeff(),
            'LMI_PAYMENT_DESC' => "Оплата счета N " . $this->_shopOrder->invoice,
            'LMI_PAYMENT_NO' => $this->_shopOrder->id,
            'LMI_MERCHANT_ID' => $this->_merchant_id,
            'LMI_CURRENCY' => htmlspecialchars($oShop_Currency->code),
            'LMI_PAYMENT_NOTIFICATION_URL' => $handler_url,
            'LMI_SUCCESS_URL' => $handler_url . "?payment=success&order_id=" . $this->_shopOrder->invoice,
            'LMI_FAILURE_URL' => $handler_url . "?payment=fail&order_id=" . $this->_shopOrder->invoice,
            'SIGN' => $this->_getSign($data),
        );

        if (isset($this->_shopOrder->email))
            $fields['LMI_PAYER_EMAIL'] = $this->_shopOrder->email;

        if (isset($this->_shopOrder->phone))
            $fields['LMI_PAYER_PHONE_NUMBER'] = $this->_shopOrder->phone;

        // Получение товарных позиций
        $items = $this->_getOrderItems($this->_shopOrder);

        print "<pre>";
        var_dump($items);
        print "</pre>";
        foreach ($items as $pos => $item) {
            $fields['LMI_SHOPPINGCART.ITEMS[' . $pos . '].NAME'] = $item['name'];
            $fields['LMI_SHOPPINGCART.ITEMS[' . $pos . '].QTY'] = round($item['quantity'], 0);
            $fields['LMI_SHOPPINGCART.ITEMS[' . $pos . '].PRICE'] = $item['price'];
            $fields['LMI_SHOPPINGCART.ITEMS[' . $pos . '].TAX'] = $this->_getProductTax($item['tax']);
        }


        $form = '
    <h1>Оплата через систему PayMaster</h1>
    <br>
    Сумма к оплате составляет <strong>' . $this->_shopOrder->sum() . '</strong>
    <br><br>
		Для оплаты нажмите кнопку "Оплатить".
    <br><br>
    <form method="POST" action="https://paymaster.ru/Payment/Init">' . PHP_EOL;
        foreach ($fields as $key => $value) {
            $form .= '<input type="hidden" name="' . $key . '" value="' . $value . '">' . PHP_EOL;
        }
        $form .= '<input type="submit" value="Оплатить">' . PHP_EOL . '</form>';

        return $form;
    }


    /**
     * Получить код счета (в данном случае равен коду перехода на оплату).
     * Используется, например, в центре администрирования при печати заказа
     */
    public function getInvoice()
    {
        return $this->getNotification();
    }


    /**
     * Преобразование ставок НДС HOSTCMS в ставки НДС Paymaster
     * @param $id
     */
    private function _getProductTax($id = '')
    {
        $taxMapper = array(
            '' => 'no_vat',
            '0' => 'no_vat',
            '2' => 'vat118',
            '5' => 'vat18',
            '19' => 'vat110',
            '20' => 'vat10',
            '21' => 'vat0',
        );
        if (array_key_exists($id,$taxMapper) == false) {
            return 'no_vat';
        } else {
            return $taxMapper[$id];
        }
    }


    /**
     * Получение подписи
     * @param $request
     * @return string
     */
    private function _getSign($request)
    {
        $hash_alg = $this->_signature_method;
        $secret_key = $this->_secret_key;
        $plain_sign = $request['LMI_MERCHANT_ID'] . $request['LMI_PAYMENT_NO'] . $request['LMI_PAID_AMOUNT'] . $request['LMI_PAID_CURRENCY'] . $secret_key;
        return base64_encode(hash($hash_alg, $plain_sign, true));
    }


    /**
     * Получение хэша
     * @param $request
     * @return string
     */
    private function _getHash($request)
    {
        $hash_alg = $this->_signature_method;
        $SECRET = $this->_secret_key;
        // Получаем ID продавца не из POST запроса, а из модуля (исключаем, тем самым его подмену)
        $LMI_MERCHANT_ID = $request['LMI_MERCHANT_ID'];
        //Получили номер заказа очень нам он нужен, смотрите ниже, что мы с ним будем вытворять
        $LMI_PAYMENT_NO = $request['LMI_PAYMENT_NO'];
        //Номер платежа в системе PayMaster
        $LMI_SYS_PAYMENT_ID = $request['LMI_SYS_PAYMENT_ID'];
        //Дата платежа
        $LMI_SYS_PAYMENT_DATE = $request['LMI_SYS_PAYMENT_DATE'];
        $LMI_PAYMENT_AMOUNT = $request['LMI_PAYMENT_AMOUNT'];
        //Теперь получаем валюту заказа, то что была в заказе
        $LMI_CURRENCY = $request['LMI_CURRENCY'];
        $LMI_PAID_AMOUNT = $request['LMI_PAID_AMOUNT'];
        $LMI_PAID_CURRENCY = $request['LMI_PAID_CURRENCY'];
        $LMI_PAYMENT_SYSTEM = $request['LMI_PAYMENT_SYSTEM'];
        $LMI_SIM_MODE = $request['LMI_SIM_MODE'];
        $string = $LMI_MERCHANT_ID . ";" . $LMI_PAYMENT_NO . ";" . $LMI_SYS_PAYMENT_ID . ";" . $LMI_SYS_PAYMENT_DATE . ";" . $LMI_PAYMENT_AMOUNT . ";" . $LMI_CURRENCY . ";" . $LMI_PAID_AMOUNT . ";" . $LMI_PAID_CURRENCY . ";" . $LMI_PAYMENT_SYSTEM . ";" . $LMI_SIM_MODE . ";" . $SECRET;
        $hash = base64_encode(hash($hash_alg, $string, true));
        return $hash;
    }


    /**
     * Получаем товары из заказа
     * @param $oShop_Order
     * @return array
     */
    protected function _getOrderItems($oShop_Order)
    {
        $aShop_Order_Items = $oShop_Order->Shop_Order_Items->findAll(FALSE);

        // Расчет сумм скидок, чтобы потом вычесть из цены каждого товара
        $discount = $amount = $quantity = 0;
        foreach ($aShop_Order_Items as $key => $oShop_Order_Item) {
            if ($oShop_Order_Item->price <= 0) {
                $discount -= $oShop_Order_Item->getAmount();
                unset($aShop_Order_Items[$key]);
            } else {
                $amount += $oShop_Order_Item->getAmount();
                $quantity += $oShop_Order_Item->quantity;
            }
        }

        $discount = $amount != 0 && $quantity != 0
            ? round(
                abs($discount) / $amount, 4, PHP_ROUND_HALF_DOWN
            )
            : 0;

        $aItems = array();

        // Рассчитываемая сумма с учетом скидок
        $calcAmount = 0;

        foreach ($aShop_Order_Items as $oShop_Order_Item) {
            if ($oShop_Order_Item->quantity) {
                $price = number_format(
                    Shop_Controller::instance()->round($oShop_Order_Item->price + $oShop_Order_Item->getTax()) * (1 - $discount),
                    2, '.', ''
                );

                $calcAmount += $price * $oShop_Order_Item->quantity;

                $aItems[] = array(
                    'name' => mb_substr($oShop_Order_Item->name, 0, 128),
                    'shop_item_id' => $oShop_Order_Item->shop_item_id,
                    'quantity' => $oShop_Order_Item->quantity,
                    'price' => $price,
                    'tax' => $oShop_Order_Item->rate
                );
            }
        }

        $totalAmount = $oShop_Order->getAmount();
        if ($calcAmount != $totalAmount) {
            $delta = $totalAmount - $calcAmount;

            end($aItems);
            $lastKey = key($aItems);

            $deltaPerOneItem = round($delta / $aItems[$lastKey]['quantity'], 2);

            $aItems[$lastKey]['price'] += $deltaPerOneItem;

            $calcAmount += $deltaPerOneItem * $aItems[$lastKey]['quantity'];

            // Если опять не равны, то добавляем новый товар
            if ($calcAmount < $totalAmount) {
                $aItems[] = array(
                    'name' => 'Округление',
                    'shop_item_id' => 0,
                    'quantity' => 1,
                    'price' => round($totalAmount - $calcAmount, 2),
                    // Ставка налога текстовая
                    'tax' => 0
                );
            }
        }

        return $aItems;
    }
}