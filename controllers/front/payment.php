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
        $address_delivery_country = new Country($billing_address->id_country);
        $iso_code = self::get_convert_country($address_delivery_country->iso_code);
        $address_delivery_state = new State($billing_address->id_state);
        $iso_code_state = self::validate_state($address_delivery_state->iso_code);
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
            'street'               => $billing_address->address1,
            'city'                 => $billing_address->city,
            'country'              => $iso_code,
            'state'                => $iso_code_state,
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
    /**
     * Method to validate two-letter state codes.
     *
     * @param string $state default prestashop
     * @return string only $state in format alpha-2(this format is required for 3ds)
     */
    public static function validate_state($state)
    {
        $pattern ="/^[A-Z]{2}$/";
        if (preg_match($pattern, $state) && !(empty($state))) {
            return $state;
        }
        return "";
    }
    /**
     * Method to change two-letter country codes to three-letter country codes.
     *
     * @param string $country default prestashop(alpha-2)
     * @return string $country in format ISO 3166-1 alpha-3 (this format is required for 3ds)
     */
    public static function get_convert_country($country)
    {
        $countries = array(
            'AF' => 'AFG', //Afghanistan
            'AX' => 'ALA', //land Islands
            'AL' => 'ALB', //Albania
            'DZ' => 'DZA', //Algeria
            'AS' => 'ASM', //American Samoa
            'AD' => 'AND', //Andorra
            'AO' => 'AGO', //Angola
            'AI' => 'AIA', //Anguilla
            'AQ' => 'ATA', //Antarctica
            'AG' => 'ATG', //Antigua and Barbuda
            'AR' => 'ARG', //Argentina
            'AM' => 'ARM', //Armenia
            'AW' => 'ABW', //Aruba
            'AU' => 'AUS', //Australia
            'AT' => 'AUT', //Austria
            'AZ' => 'AZE', //Azerbaijan
            'BS' => 'BHS', //Bahamas
            'BH' => 'BHR', //Bahrain
            'BD' => 'BGD', //Bangladesh
            'BB' => 'BRB', //Barbados
            'BY' => 'BLR', //Belarus
            'BE' => 'BEL', //Belgium
            'BZ' => 'BLZ', //Belize
            'BJ' => 'BEN', //Benin
            'BM' => 'BMU', //Bermuda
            'BT' => 'BTN', //Bhutan
            'BO' => 'BOL', //Bolivia
            'BQ' => 'BES', //Bonaire, Saint Estatius and Saba
            'BA' => 'BIH', //Bosnia and Herzegovina
            'BW' => 'BWA', //Botswana
            'BV' => 'BVT', //Bouvet Islands
            'BR' => 'BRA', //Brazil
            'IO' => 'IOT', //British Indian Ocean Territory
            'BN' => 'BRN', //Brunei
            'BG' => 'BGR', //Bulgaria
            'BF' => 'BFA', //Burkina Faso
            'BI' => 'BDI', //Burundi
            'KH' => 'KHM', //Cambodia
            'CM' => 'CMR', //Cameroon
            'CA' => 'CAN', //Canada
            'CV' => 'CPV', //Cape Verde
            'KY' => 'CYM', //Cayman Islands
            'CF' => 'CAF', //Central African Republic
            'TD' => 'TCD', //Chad
            'CL' => 'CHL', //Chile
            'CN' => 'CHN', //China
            'CX' => 'CXR', //Christmas Island
            'CC' => 'CCK', //Cocos (Keeling) Islands
            'CO' => 'COL', //Colombia
            'KM' => 'COM', //Comoros
            'CG' => 'COG', //Congo
            'CD' => 'COD', //Congo, Democratic Republic of the
            'CK' => 'COK', //Cook Islands
            'CR' => 'CRI', //Costa Rica
            'CI' => 'CIV', //Côte d Ivoire
            'HR' => 'HRV', //Croatia
            'CU' => 'CUB', //Cuba
            'CW' => 'CUW', //Curaçao
            'CY' => 'CYP', //Cyprus
            'CZ' => 'CZE', //Czech Republic
            'DK' => 'DNK', //Denmark
            'DJ' => 'DJI', //Djibouti
            'DM' => 'DMA', //Dominica
            'DO' => 'DOM', //Dominican Republic
            'EC' => 'ECU', //Ecuador
            'EG' => 'EGY', //Egypt
            'SV' => 'SLV', //El Salvador
            'GQ' => 'GNQ', //Equatorial Guinea
            'ER' => 'ERI', //Eritrea
            'EE' => 'EST', //Estonia
            'ET' => 'ETH', //Ethiopia
            'FK' => 'FLK', //Falkland Islands
            'FO' => 'FRO', //Faroe Islands
            'FJ' => 'FIJ', //Fiji
            'FI' => 'FIN', //Finland
            'FR' => 'FRA', //France
            'GF' => 'GUF', //French Guiana
            'PF' => 'PYF', //French Polynesia
            'TF' => 'ATF', //French Southern Territories
            'GA' => 'GAB', //Gabon
            'GM' => 'GMB', //Gambia
            'GE' => 'GEO', //Georgia
            'DE' => 'DEU', //Germany
            'GH' => 'GHA', //Ghana
            'GI' => 'GIB', //Gibraltar
            'GR' => 'GRC', //Greece
            'GL' => 'GRL', //Greenland
            'GD' => 'GRD', //Grenada
            'GP' => 'GLP', //Guadeloupe
            'GU' => 'GUM', //Guam
            'GT' => 'GTM', //Guatemala
            'GG' => 'GGY', //Guernsey
            'GN' => 'GIN', //Guinea
            'GW' => 'GNB', //Guinea-Bissau
            'GY' => 'GUY', //Guyana
            'HT' => 'HTI', //Haiti
            'HM' => 'HMD', //Heard Island and McDonald Islands
            'VA' => 'VAT', //Holy See (Vatican City State)
            'HN' => 'HND', //Honduras
            'HK' => 'HKG', //Hong Kong
            'HU' => 'HUN', //Hungary
            'IS' => 'ISL', //Iceland
            'IN' => 'IND', //India
            'ID' => 'IDN', //Indonesia
            'IR' => 'IRN', //Iran
            'IQ' => 'IRQ', //Iraq
            'IE' => 'IRL', //Republic of Ireland
            'IM' => 'IMN', //Isle of Man
            'IL' => 'ISR', //Israel
            'IT' => 'ITA', //Italy
            'JM' => 'JAM', //Jamaica
            'JP' => 'JPN', //Japan
            'JE' => 'JEY', //Jersey
            'JO' => 'JOR', //Jordan
            'KZ' => 'KAZ', //Kazakhstan
            'KE' => 'KEN', //Kenya
            'KI' => 'KIR', //Kiribati
            'KP' => 'PRK', //Korea, Democratic People\'s Republic of
            'KR' => 'KOR', //Korea, Republic of (South)
            'KW' => 'KWT', //Kuwait
            'KG' => 'KGZ', //Kyrgyzstan
            'LA' => 'LAO', //Laos
            'LV' => 'LVA', //Latvia
            'LB' => 'LBN', //Lebanon
            'LS' => 'LSO', //Lesotho
            'LR' => 'LBR', //Liberia
            'LY' => 'LBY', //Libya
            'LI' => 'LIE', //Liechtenstein
            'LT' => 'LTU', //Lithuania
            'LU' => 'LUX', //Luxembourg
            'MO' => 'MAC', //Macao S.A.R., China
            'MK' => 'MKD', //Macedonia
            'MG' => 'MDG', //Madagascar
            'MW' => 'MWI', //Malawi
            'MY' => 'MYS', //Malaysia
            'MV' => 'MDV', //Maldives
            'ML' => 'MLI', //Mali
            'MT' => 'MLT', //Malta
            'MH' => 'MHL', //Marshall Islands
            'MQ' => 'MTQ', //Martinique
            'MR' => 'MRT', //Mauritania
            'MU' => 'MUS', //Mauritius
            'YT' => 'MYT', //Mayotte
            'MX' => 'MEX', //Mexico
            'FM' => 'FSM', //Micronesia
            'MD' => 'MDA', //Moldova
            'MC' => 'MCO', //Monaco
            'MN' => 'MNG', //Mongolia
            'ME' => 'MNE', //Montenegro
            'MS' => 'MSR', //Montserrat
            'MA' => 'MAR', //Morocco
            'MZ' => 'MOZ', //Mozambique
            'MM' => 'MMR', //Myanmar
            'NA' => 'NAM', //Namibia
            'NR' => 'NRU', //Nauru
            'NP' => 'NPL', //Nepal
            'NL' => 'NLD', //Netherlands
            'AN' => 'ANT', //Netherlands Antilles
            'NC' => 'NCL', //New Caledonia
            'NZ' => 'NZL', //New Zealand
            'NI' => 'NIC', //Nicaragua
            'NE' => 'NER', //Niger
            'NG' => 'NGA', //Nigeria
            'NU' => 'NIU', //Niue
            'NF' => 'NFK', //Norfolk Island
            'MP' => 'MNP', //Northern Mariana Islands
            'NO' => 'NOR', //Norway
            'OM' => 'OMN', //Oman
            'PK' => 'PAK', //Pakistan
            'PW' => 'PLW', //Palau
            'PS' => 'PSE', //Palestinian Territory
            'PA' => 'PAN', //Panama
            'PG' => 'PNG', //Papua New Guinea
            'PY' => 'PRY', //Paraguay
            'PE' => 'PER', //Peru
            'PH' => 'PHL', //Philippines
            'PN' => 'PCN', //Pitcairn
            'PL' => 'POL', //Poland
            'PT' => 'PRT', //Portugal
            'PR' => 'PRI', //Puerto Rico
            'QA' => 'QAT', //Qatar
            'RE' => 'REU', //Reunion
            'RO' => 'ROU', //Romania
            'RU' => 'RUS', //Russia
            'RW' => 'RWA', //Rwanda
            'BL' => 'BLM', //Saint Barth&eacute;lemy
            'SH' => 'SHN', //Saint Helena
            'KN' => 'KNA', //Saint Kitts and Nevis
            'LC' => 'LCA', //Saint Lucia
            'MF' => 'MAF', //Saint Martin (French part)
            'SX' => 'SXM', //Sint Maarten / Saint Matin (Dutch part)
            'PM' => 'SPM', //Saint Pierre and Miquelon
            'VC' => 'VCT', //Saint Vincent and the Grenadines
            'WS' => 'WSM', //Samoa
            'SM' => 'SMR', //San Marino
            'ST' => 'STP', //S&atilde;o Tom&eacute; and Pr&iacute;ncipe
            'SA' => 'SAU', //Saudi Arabia
            'SN' => 'SEN', //Senegal
            'RS' => 'SRB', //Serbia
            'SC' => 'SYC', //Seychelles
            'SL' => 'SLE', //Sierra Leone
            'SG' => 'SGP', //Singapore
            'SK' => 'SVK', //Slovakia
            'SI' => 'SVN', //Slovenia
            'SB' => 'SLB', //Solomon Islands
            'SO' => 'SOM', //Somalia
            'ZA' => 'ZAF', //South Africa
            'GS' => 'SGS', //South Georgia/Sandwich Islands
            'SS' => 'SSD', //South Sudan
            'ES' => 'ESP', //Spain
            'LK' => 'LKA', //Sri Lanka
            'SD' => 'SDN', //Sudan
            'SR' => 'SUR', //Suriname
            'SJ' => 'SJM', //Svalbard and Jan Mayen
            'SZ' => 'SWZ', //Swaziland
            'SE' => 'SWE', //Sweden
            'CH' => 'CHE', //Switzerland
            'SY' => 'SYR', //Syria
            'TW' => 'TWN', //Taiwan
            'TJ' => 'TJK', //Tajikistan
            'TZ' => 'TZA', //Tanzania
            'TH' => 'THA', //Thailand
            'TL' => 'TLS', //Timor-Leste
            'TG' => 'TGO', //Togo
            'TK' => 'TKL', //Tokelau
            'TO' => 'TON', //Tonga
            'TT' => 'TTO', //Trinidad and Tobago
            'TN' => 'TUN', //Tunisia
            'TR' => 'TUR', //Turkey
            'TM' => 'TKM', //Turkmenistan
            'TC' => 'TCA', //Turks and Caicos Islands
            'TV' => 'TUV', //Tuvalu
            'UG' => 'UGA', //Uganda
            'UA' => 'UKR', //Ukraine
            'AE' => 'ARE', //United Arab Emirates
            'GB' => 'GBR', //United Kingdom
            'US' => 'USA', //United States
            'UM' => 'UMI', //United States Minor Outlying Islands
            'UY' => 'URY', //Uruguay
            'UZ' => 'UZB', //Uzbekistan
            'VU' => 'VUT', //Vanuatu
            'VE' => 'VEN', //Venezuela
            'VN' => 'VNM', //Vietnam
            'VG' => 'VGB', //Virgin Islands, British
            'VI' => 'VIR', //Virgin Island, U.S.
            'WF' => 'WLF', //Wallis and Futuna
            'EH' => 'ESH', //Western Sahara
            'YE' => 'YEM', //Yemen
            'ZM' => 'ZMB', //Zambia
            'ZW' => 'ZWE', //Zimbabwe

        );
        return isset($countries[$country]) ? $countries[$country] : $country;
    }
}