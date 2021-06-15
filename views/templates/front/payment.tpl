{extends "$layout"}
{block name="content"}
    <link rel="stylesheet" href="{$urls.base_url}/modules/pg_prestashop_plugin/views/css/main.css">
    <script src="https://cdn.paymentez.com/ccapi/sdk/payment_checkout_stable.min.js"></script>
    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js" type="text/javascript"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/3.1.9-1/crypto-js.js"></script></head>

    <div class="row">
        <div class="payment-title col-sm-7 col-lg-9">
            <h3 class="text-xs-center text-md-left">
                <span>
                    {l s='Review your items to checkout' mod='pg_prestashop_plugin'}
                </span>
            </h3>
        </div>
        <div class="col-sm-5 col-lg-3">
            <button class="btn btn-primary btn-block js-payment-checkout">
                <i class="material-icons">done</i>
                <span>
                    {l s='Purchase' mod='pg_prestashop_plugin'}
                </span>
            </button>
            <button class="btn btn-primary btn-block" onclick="ltpRedirect()">
                <i class="material-icons">done</i>
                <span>
                    {l s='LinkToPay' mod='pg_prestashop_plugin'}
                </span>
            </button>
        </div>
    </div>
    <hr>
    <div class="row">
        <div class="col-md-7">
            <ul class="list-group list-group-flush">
                {foreach $products as $product}
                <li class="list-group-item">
                    <span class="item-name text-dark">
                        <b>{$product.name}</b>
                    </span><br>
                    {if $product.attributes}
                    <span class="item-name text-dark">
                        {$product.attributes}
                    </span><br>
                    {/if}
                    <span class="item-price">
                        <span class="text-primary">{Tools::displayPrice($product.price_wt)}</span> - <span
                            class="text-muted">Cantidad:
                            {$product.quantity}</span>
                    </span><br>
                    {if $product.attributes}
                    <span class="items-details">
                        {$product.description_short nofilter}
                    </span>
                    {/if}
                </li>
                {/foreach}
            </ul>
            <p class="payment-modify text-xs-center text-md-left">
                <a href="{$urls.pages.cart}?action=show">
                    <span>
                        {l s='Modify or delete items' mod='pg_prestashop_plugin'}
                    </span>
                </a>
            </p>
        </div>
    </div>
    <hr>
    <div class="row mb-1">
        <div class="col-sm-7 col-lg-9">
        </div>
        <div class="col-sm-5 col-lg-3">
            <button class="btn btn-primary btn-block js-payment-checkout">
                <i class="material-icons">done</i>
                <span>
                    {l s='Purchase' mod='pg_prestashop_plugin'}
                </span>
            </button>
            <button class="btn btn-primary btn-block" onclick="ltpRedirect()">
                <i class="material-icons">done</i>
                <span>
                    {l s='LinkToPay' mod='pg_prestashop_plugin'}
                </span>
            </button>
        </div>
    </div>

    <div id="response"></div>

    <script id="payment_ltp" type="text/javascript">

        function generateAuthToken() {
            let timestamp = (new Date()).getTime();
            let key_time = "{$app_key}" + timestamp;
            let uniq_token = CryptoJS.SHA256(key_time);
            let str_union = "{$app_code}"+";"+timestamp+";"+uniq_token
            return btoa(str_union);
        }

        function ltpRedirect() {
            let xhr = new XMLHttpRequest();
            xhr.open("POST", "https://noccapi-stg.paymentez.com/linktopay/init_order/", true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('Auth-token', generateAuthToken());
            xhr.send(JSON.stringify({
                "user": {
                    "id": "{$user_id}",
                    "email": "{$user_email}",
                    "name": "{$user_firstname}",
                    "last_name": "{$user_lastname}"
                },
                "order": {
                    "dev_reference": "{$order_reference}",
                    "description": "{$order_description}",
                    "amount": Number("{$order_amount}"),
                    "installments_type": -1,
                    "vat": Number("{$order_vat}"),
                    "currency": "{$currency}"
                },
                "configuration": {
                    "partial_payment": false,
                    "expiration_days": Number("{$ltp_expiration_days}"),
                    "allowed_payment_methods": ["All"],
                    "success_url": "{$order_url}",
                    "failure_url": "{$order_url}",
                    "pending_url": "{$order_url}",
                    "review_url": "{$order_url}"
                }
            }));
            xhr.onload = function() {
                let data = JSON.parse(this.responseText);
                console.log(data);
                if (!data.hasOwnProperty('success')) {
                    showErrorMessage(this.responseText)
                } else if (!data['success']) {
                    showErrorMessage(data['detail'] ?? this.responseText)
                } else {
                    window.location = data['data']['payment']['payment_url'];
                }
            }
        }

        function showErrorMessage(message) {
            let errorMessage = "{l s='Failed to generate the LinkToPay, gateway response: ' mod='pg_prestashop_plugin'}";
            window.alert(errorMessage + JSON.stringify(message));
        }
    </script>

    <script id="payment_checkout" type="text/javascript">
        jQuery(document).ready(function ($) {
            let paymentCheckout = new PaymentCheckout.modal({
                client_app_code: "{$app_code}",
                client_app_key: "{$app_key}",
                locale: "{$checkout_language}",
                env_mode: "local",
                onOpen: function () {
                    console.log('modal open');
                },
                onClose: function () {
                    console.log('modal closed');
                },
                onResponse: function (response) {
                    console.log('modal response');
                    if (response.transaction["status_detail"] === 3 || response.transaction["status_detail"] === 0) {
                        showMessageSuccess(response);
                    } else {
                        showMessageError();
                    }
                }
            });

            let btnOpenCheckout = $('.js-payment-checkout');

            btnOpenCheckout.each(function () {
                $(this).on('click', function () {
                    paymentCheckout.open({
                        user_id: "{$user_id}",
                        user_email: "{$user_email}", //optional
                        order_description: "{$order_description}",
                        order_amount: Number("{$order_amount}"),
                        order_vat: Number("{$order_vat}"),
                        order_reference: "{$order_reference}",
                        //order_installments_type: 2, // optional: For Colombia an Brazil to show installments should be 0, For Ecuador the valid values are: https://paymentez.github.io/api-doc/#payment-methods-cards-debit-with-token-installments-type
                        //order_taxable_amount: 0, // optional: Only available for Ecuador. The taxable amount, if it is zero, it is calculated on the total. Format: Decimal with two fraction digits.
                        //order_tax_percentage: 10 // optional: Only available for Ecuador. The tax percentage to be applied to this order.
                    });
                })
            }
            );

            // Close Checkout on page navigation:
            window.addEventListener('popstate', function () {
                paymentCheckout.close();
            });

            function showMessageSuccess(params) {
                console.log("success");

                const form = document.createElement('form');
                form.method = 'post';

                for (const key in params) {
                    if (params.hasOwnProperty(key) && key === 'transaction') {
                        for (const t_key in params[key]) {
                            const hiddenField = document.createElement('input');
                            hiddenField.type = 'hidden';
                            hiddenField.name = t_key;
                            hiddenField.value = params[key][t_key];
                            form.appendChild(hiddenField);
                        }
                    }
                }

                document.body.appendChild(form);
                form.submit();
            }

            function showMessageError() {
                console.error("error");
                window.alert('{l s='An error occurred while processing your payment and could not be made. Try another Credit Card.' mod='pg_prestashop_plugin'}');
            }
        });
    </script>
{/block}