{extends "$layout"}
{block name="content"}
{* <link rel="stylesheet" href="{$urls.base_url}/modules/pg_prestashop_plugin/views/css/main.css"> *}
<script src="https://cdn.paymentez.com/ccapi/sdk/payment_checkout_stable.min.js"></script>
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js" type="text/javascript"></script>

<div class="row">
    <div class="payment-title col-sm-7 col-lg-9">
        <h3 class="text-xs-center text-md-left">
            Review your items to checkout
        </h3>
    </div>
    <div class="col-sm-5 col-lg-3">
        <button class="btn btn-primary btn-block js-payment-checkout">
            <i class="material-icons">done</i>
            Purchase
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
                Modify or delete items
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
            Purchase
        </button>
    </div>
</div>

<div id="response"></div>

<script id="payment_checkout" type="text/javascript">
    jQuery(document).ready(function ($) {
        // console.log("{$order_reference}");
        let paymentCheckout = new PaymentCheckout.modal({
            client_app_code: "{$app_code}",
            client_app_key: "{$app_key}",
            locale: "{$checkout_language}",
            env_mode: "{$environment}",
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
        }
    });
</script>
{/block}