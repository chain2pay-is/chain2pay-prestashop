{*
 * Chain2Pay payments of an order, shown on the order page of the back office.
 * @license GPL-3.0-or-later
 *}
<div class="card mt-2">
  <div class="card-header">
    <h3 class="card-header-title">Chain2Pay</h3>
  </div>
  <div class="card-body">
    {if $chain2pay_rows|@count > 0}
      <table class="table">
        <thead>
          <tr>
            <th>Payment</th>
            <th>Provider</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Transaction</th>
            <th>Created</th>
            <th>Updated</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$chain2pay_rows item=row}
            <tr>
              <td>{$row.payment_id|escape:'htmlall':'UTF-8'}</td>
              <td>{$row.provider|escape:'htmlall':'UTF-8'}</td>
              <td>{$row.amount|escape:'htmlall':'UTF-8'} {$row.currency|escape:'htmlall':'UTF-8'}</td>
              <td>{$row.status|escape:'htmlall':'UTF-8'}</td>
              <td style="word-break:break-all">{$row.tx_hash|escape:'htmlall':'UTF-8'}</td>
              <td>{$row.date_add|escape:'htmlall':'UTF-8'}</td>
              <td>{$row.date_upd|escape:'htmlall':'UTF-8'}</td>
            </tr>
            {if $row.note}
              <tr>
                <td colspan="7" class="text-muted">{$row.note|escape:'htmlall':'UTF-8'}</td>
              </tr>
            {/if}
          {/foreach}
        </tbody>
      </table>
    {else}
      <p class="mb-0">No Chain2Pay payment was created for this order.</p>
    {/if}
  </div>
</div>
