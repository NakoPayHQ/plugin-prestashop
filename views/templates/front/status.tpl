{*
 * NakoPay payment status page.
 * Receives:
 *   $nakopay.invoice_id, .address, .amount, .currency, .coin, .fiat,
 *   .bip21, .status, .poll_url
 *}
<div class="nakopay-checkout" data-invoice="{$nakopay.invoice_id|escape:'htmlall':'UTF-8'}">
  <div class="nakopay-card">
    <header class="nakopay-header">
      <h2>{l s='Pay with crypto' mod='nakopay'}</h2>
      <span class="nakopay-invoice-id">{$nakopay.invoice_id|escape:'htmlall':'UTF-8'}</span>
    </header>

    <div class="nakopay-amount">
      <div class="nakopay-amount-fiat">{$nakopay.fiat|escape:'htmlall':'UTF-8'} {$nakopay.currency|escape:'htmlall':'UTF-8'}</div>
      {if $nakopay.amount}
        <div class="nakopay-amount-crypto">{$nakopay.amount|escape:'htmlall':'UTF-8'} {$nakopay.coin|escape:'htmlall':'UTF-8'}</div>
      {/if}
    </div>

    <div class="nakopay-qr">
      <canvas id="nakopay-qr-canvas" width="220" height="220"></canvas>
    </div>

    <div class="nakopay-row">
      <label>{l s='Address' mod='nakopay'}</label>
      <div class="nakopay-input-row">
        <input id="nakopay-address" type="text" readonly value="{$nakopay.address|escape:'htmlall':'UTF-8'}">
        <button type="button" class="nakopay-copy-btn" data-target="nakopay-address">{l s='Copy' mod='nakopay'}</button>
      </div>
    </div>

    {if $nakopay.bip21}
      <div class="nakopay-row">
        <a class="nakopay-wallet-btn" href="{$nakopay.bip21|escape:'htmlall':'UTF-8'}">{l s='Open in wallet' mod='nakopay'}</a>
      </div>
    {/if}

    <p id="nakopay-status" class="nakopay-status" data-status="{$nakopay.status|escape:'htmlall':'UTF-8'}">
      {l s='Waiting for payment…' mod='nakopay'}
    </p>
  </div>
</div>

<script>
  window.NAKOPAY = {
    pollUrl:  {$nakopay.poll_url|json_encode nofilter},
    address:  {$nakopay.address|json_encode nofilter},
    amount:   {$nakopay.amount|json_encode nofilter},
    bip21:    {$nakopay.bip21|json_encode nofilter},
    coin:     {$nakopay.coin|json_encode nofilter}
  };
</script>
