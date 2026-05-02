<div class="nakopay-confirmation">
  <p>{l s='Thank you. Your crypto payment is being processed.' mod='nakopay'}</p>
  {if $reference}<p>{l s='Order reference:' mod='nakopay'} <strong>{$reference|escape:'htmlall':'UTF-8'}</strong></p>{/if}
  <p>{l s='You will receive an email when the payment is confirmed on the network.' mod='nakopay'}</p>
</div>
