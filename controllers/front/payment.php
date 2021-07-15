<?php
class PG_Prestashop_PluginPaymentModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        if(!$this->module->active)
        {
            Tools::redirect($this->context->link->getPageLink('order'));
        }
        $customer = $this->context->customer;
        if(!Validate::isLoadedObject($customer))
        {
            Tools::redirect($this->context->link->getPageLink('order'));
        }
    }

    /**
     * @throws PrestaShopException
     * @throws Exception
     */
    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;
        $customer = $this->context->customer;
        $billing_address = new Address($cart->id_address_invoice);
        $total = (float)$cart->getOrderTotal();
        $products = $cart->getProducts();
        $order_products = [];
        foreach ($products as $product)
            $order_products[] = $product['cart_quantity']." X ".$product['name'];
        $order_description = implode(", ", $order_products);
        if (strlen($order_description) > 240)
        {
            $order_description = substr($order_description,0,240);
        }
        $checkout_language = $this->mapCheckoutLanguage(Configuration::get('checkout_language'));
        $environment       = $this->mapEnvironment(Configuration::get('environment'));

        $this->context->smarty->assign([
            'app_code_client'      => Configuration::get('app_code_client'),
            'app_key_client'       => Configuration::get('app_key_client'),
            'app_code_server'      => Configuration::get('app_code_server'),
            'app_key_server'       => Configuration::get('app_key_server'),
            'checkout_language'    => $checkout_language,
            'environment'          => $environment,
            'ltp_url'              => $this->mapLinkToPayUrl($environment),
            'user_id'              => $cart->id_customer,
            'user_email'           => $customer->email,
            'order_description'    => $order_description,
            'order_amount'         => $total,
            'order_vat'            => 0.0,
            'order_reference'      => $cart->id,
            'products'             => $products,
            'user_firstname'       => $customer->firstname,
            'user_lastname'        => $customer->lastname,
            'currency'             => Currency::getIsoCodeById($cart->id_currency),
            'expiration_days'      => Configuration::get('ltp_expiration_days'),
            'order_url'            => Context::getContext()->shop->getBaseURL(true).'index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key,
            'ltp_button_text'      => Configuration::get('ltp_button_text'),
            'card_button_text'     => Configuration::get('card_button_text'),
            'enable_installments'  => Configuration::get('enable_installments'),
            'installments_options' => $this->getInstallmentsOptions(),
            'enable_card'          => Configuration::get('enable_card'),
            'enable_ltp'           => Configuration::get('enable_ltp'),
            'city'                 => $billing_address->city,
            # TODO: MAPEAR PARA QUE SEA FORMATO MEX, COL, ECU, ETC
            'country'              => $billing_address->country,
            'state'                => State::getNameById($billing_address->id_state),
            'street'               => $billing_address->address1,
            'zip'                  => $billing_address->postcode
        ]);

        $this->setTemplate('module:pg_prestashop_plugin/views/templates/front/payment.tpl');
    }

    public function setMedia()
    {
        parent::setMedia();
    }

    public function postProcess()
    {
        if (!empty($_POST))
        {
            $cart           = $this->context->cart;
            $customer       = new Customer($cart->id_customer);

            $total          = (float)Tools::getValue('amount');
            $payment_id     = Tools::getValue('id');
            $status         = Tools::getValue('status');
            $payment_method = Tools::getValue('payment_method');

            if ($payment_method == 'LinkToPay')
            {
                $this->module->validateOrder($cart->id, Configuration::get('PS_OS_BANKWIRE'), $total, $this->module->displayName, null, array(), $this->context->currency->id, false, $customer->secure_key);

                $this->assignPaymentId($payment_id);

                $payment_url    = Tools::getValue('payment_url');
                $this->context->smarty->assign([
                    'pg_status'      => 'pending',
                    'payment_id'     => Tools::getValue('id'),
                    'module_gtw'     => $this->module->displayName,
                    'payment_method' => $payment_method,
                    'payment_url'    => $payment_url
                ]);
                Tools::redirect($payment_url);
            }

            if ($status == 'success')
            {
                $this->module->validateOrder($cart->id, Configuration::get('PS_OS_PAYMENT'), $total, $this->module->displayName, null, array(), $this->context->currency->id, false, $customer->secure_key);
            }
            elseif ($status == 'pending')
            {
                $this->module->validateOrder($cart->id, Configuration::get('PS_OS_PREPARATION'), $total, $this->module->displayName, null, array(), $this->context->currency->id, false, $customer->secure_key);
            }
            else
            {
                $this->module->validateOrder($cart->id, Configuration::get('PS_OS_ERROR'), $total, $this->module->displayName, null, array(), $this->context->currency->id, false, $customer->secure_key);
            }

            $this->assignPaymentId($payment_id);

            $this->context->smarty->assign([
                'pg_status'      => $status,
                'payment_id'     => $payment_id,
                'module_gtw'     => $this->module->displayName,
                'payment_method' => $payment_method
            ]);
            Tools::redirect('index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);
        }
    }

    private function assignPaymentId($payment_id) {
        $order      = new Order($this->module->currentOrder);
        $collection = OrderPayment::getByOrderReference($order->reference);
        if (count($collection) > 0)
        {
            foreach ($collection as $order_payment)
            {
                if ($order_payment->payment_method == FLAVOR . ' Prestashop Plugin')
                {
                    $order_payment->transaction_id = $payment_id;
                    $order_payment->update();
                }
            }
        }
    }

    private function mapCheckoutLanguage($checkout_language): string
    {
        return  [1 => 'en', 2 => 'es', 3 => 'pt',][$checkout_language];
    }

    private function mapEnvironment($environment): string
    {
        return [1 => 'stg', 2 => 'prod',][$environment];
    }

    private function mapLinkToPayUrl($environment): string
    {
        return [
            'stg' => 'https://noccapi-stg.'.FLAVOR_DOMAIN.'/linktopay/init_order/',
            'prod' => 'https://noccapi.'.FLAVOR_DOMAIN.'/linktopay/init_order/'
        ][$environment];
    }

    private function getInstallmentsOptions(): array
    {
        return [
            1  => $this->module->l('Revolving and deferred without interest (The bank will pay to the commerce the installment, month by month)(Ecuador)', 'payment'),
            2  => $this->module->l('Deferred with interest (Ecuador, México)', 'payment'),
            3  => $this->module->l('Deferred without interest (Ecuador, México)', 'payment'),
            7  => $this->module->l('Deferred with interest and months of grace (Ecuador)', 'payment'),
            6  => $this->module->l('Deferred without interest pay month by month (Ecuador)(Medianet)', 'payment'),
            9  => $this->module->l('Deferred without interest and months of grace (Ecuador, México)', 'payment'),
            10 => $this->module->l('Deferred without interest promotion bimonthly (Ecuador)(Medianet)', 'payment'),
            21 => $this->module->l('For Diners Club exclusive, deferred with and without interest (Ecuador)', 'payment'),
            22 => $this->module->l('For Diners Club exclusive, deferred with and without interest (Ecuador)', 'payment'),
            30 => $this->module->l('Deferred with interest pay month by month (Ecuador)(Medianet)', 'payment'),
            50 => $this->module->l('Deferred without interest promotions (Supermaxi)(Ecuador)(Medianet)', 'payment'),
            51 => $this->module->l('Deferred with interest (Cuota fácil)(Ecuador)(Medianet)', 'payment'),
            52 => $this->module->l('Without interest (Rendecion Produmillas)(Ecuador)(Medianet)', 'payment'),
            53 => $this->module->l('Without interest sale with promotions (Ecuador)(Medianet)', 'payment'),
            70 => $this->module->l('Deferred special without interest (Ecuador)(Medianet)', 'payment'),
            72 => $this->module->l('Credit without interest (cte smax)(Ecuador)(Medianet)', 'payment'),
            73 => $this->module->l('Special credit without interest (smax)(Ecuador)(Medianet)', 'payment'),
            74 => $this->module->l('Prepay without interest (smax)(Ecuador)(Medianet)', 'payment'),
            75 => $this->module->l('Defered credit without interest (smax)(Ecuador)(Medianet)', 'payment'),
            90 => $this->module->l('Without interest with months of grace (Supermaxi)(Ecuador)(Medianet)', 'payment'),
        ];
    }
}