{if $message}
    <div class="alert alert-{if $message.type == 'error'}danger{else}{$message.type|escape}{/if}" role="alert">
        {$message.text|escape}
    </div>
{/if}

{if $available}
    <h2>Email Forwarding for {$domain|escape}</h2>

    {if !$state}
        <div class="alert alert-warning" role="alert">
            <strong>Existing email will stop working.</strong>
            Enabling forwarding replaces this domain's MX records and adds the exact Forward Email verification record. Replaced MX records are saved to your WHMCS customer notes but are not restored automatically.
        </div>
        <form method="post" action="index.php?m=forward_email&amp;service_id={$serviceId|escape}">
            <input type="hidden" name="token" value="{$token|escape}">
            <input type="hidden" name="action" value="enable">
            <div class="checkbox">
                <label><input type="checkbox" name="confirm_mx_replacement" value="yes" required> I understand that the current mail configuration will be replaced.</label>
            </div>
            <button class="btn btn-danger" type="submit">Enable Email Forwarding</button>
        </form>
    {else}
        <div class="alert alert-{if $state.status == 'active'}success{elseif $state.status == 'pending_verification'}warning{else}info{/if}" role="status">
            Status: <strong>{$state.status|replace:'_':' '|capitalize|escape}</strong>
            {if $state.last_error}<br>Automatic retry scheduled after the last provider error.{/if}
        </div>

        {if $state.status == 'connected' || $state.status == 'enabling'}
            <div class="alert alert-warning" role="alert">
                Configuring forwarding replaces every MX record and adds this Forward Email verification record. Sender verification records are not changed.
            </div>
            <form method="post" action="index.php?m=forward_email&amp;service_id={$serviceId|escape}">
                <input type="hidden" name="token" value="{$token|escape}">
                <input type="hidden" name="action" value="enable">
                {if $state.status == 'connected'}
                    <div class="checkbox">
                        <label><input type="checkbox" name="confirm_mx_replacement" value="yes" required> I understand that every current MX record will be replaced.</label>
                    </div>
                {else}
                    <input type="hidden" name="confirm_mx_replacement" value="yes">
                {/if}
                <button class="btn btn-warning" type="submit">{if $state.status == 'connected'}Configure Forwarding DNS{else}Retry Setup{/if}</button>
            </form>
        {elseif $state.status == 'pending_verification' || $state.status == 'active'}
            <form method="post" action="index.php?m=forward_email&amp;service_id={$serviceId|escape}" class="form-inline" style="margin-bottom:20px">
                <input type="hidden" name="token" value="{$token|escape}">
                <input type="hidden" name="action" value="verify">
                <button class="btn btn-default" type="submit">Verify Now</button>
            </form>

            <h3>Add Forwarder</h3>
            <form method="post" action="index.php?m=forward_email&amp;service_id={$serviceId|escape}" class="form-inline" style="margin-bottom:20px">
                <input type="hidden" name="token" value="{$token|escape}">
                <input type="hidden" name="action" value="add_alias">
                <label class="sr-only" for="forward-email-name">From</label>
                <div class="input-group">
                    <input id="forward-email-name" class="form-control" name="name" required maxlength="64" placeholder="sales" aria-label="Forwarder address">
                    <span class="input-group-addon">@{$domain|escape}</span>
                </div>
                <label class="sr-only" for="forward-email-destination">Destination</label>
                <input id="forward-email-destination" class="form-control" type="email" name="destination" required maxlength="254" placeholder="you@example.net">
                <button class="btn btn-primary" type="submit">Add Forwarder</button>
            </form>

            <h3>Forwarders</h3>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead><tr><th>From</th><th>Destination</th><th>Actions</th></tr></thead>
                    <tbody>
                    {foreach $aliases as $alias}
                        <tr>
                            <td>{$alias.name|escape}@{$domain|escape}</td>
                            <td>
                                <input class="form-control input-sm" type="email" name="destination" value="{$alias.recipients_display|escape}" form="forward-email-update-{$alias.id|escape}" required maxlength="254" aria-label="Destination for {$alias.name|escape}">
                            </td>
                            <td>
                                <form id="forward-email-update-{$alias.id|escape}" method="post" action="index.php?m=forward_email&amp;service_id={$serviceId|escape}" style="display:inline-block">
                                    <input type="hidden" name="token" value="{$token|escape}">
                                    <input type="hidden" name="action" value="update_alias">
                                    <input type="hidden" name="alias_id" value="{$alias.id|escape}">
                                    <button class="btn btn-warning btn-sm" type="submit">Update</button>
                                </form>
                                <form method="post" action="index.php?m=forward_email&amp;service_id={$serviceId|escape}" style="display:inline-block" onsubmit="return confirm('Delete this forwarder?')">
                                    <input type="hidden" name="token" value="{$token|escape}">
                                    <input type="hidden" name="action" value="delete_alias">
                                    <input type="hidden" name="alias_id" value="{$alias.id|escape}">
                                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    {foreachelse}
                        <tr><td colspan="3">No forwarders configured.</td></tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>

            {if $state.status == 'active' && !$state.sender_dns_configured_at && $senderDnsRecords}
                <hr>
                <details>
                    <summary><strong>Sender Verification</strong></summary>
                    <div class="alert alert-warning" role="alert">
                        <strong>This overwrites sender-authentication DNS.</strong>
                        The following SPF, DKIM, Return-Path, and DMARC records will replace conflicting records. WHMCS will not remove or restore them later.
                        <ul>
                        {foreach $senderDnsRecords as $record}
                            <li><code>{$record.name|escape} {$record.type|escape} {$record.value|escape}</code></li>
                        {/foreach}
                        </ul>
                    </div>
                    <form method="post" action="index.php?m=forward_email&amp;service_id={$serviceId|escape}">
                        <input type="hidden" name="token" value="{$token|escape}">
                        <input type="hidden" name="action" value="configure_sender_dns">
                        <div class="checkbox">
                            <label><input type="checkbox" name="confirm_sender_dns" value="yes" required> I understand these sender DNS records will be overwritten and must be maintained or removed manually.</label>
                        </div>
                        <button class="btn btn-warning" type="submit">Configure Sender Verification</button>
                    </form>
                </details>
            {/if}
        {/if}

        <hr>
        <details>
            <summary><strong>Disable Email Forwarding</strong></summary>
            <p>This permanently deletes the Forward Email domain, all forwarders, and managed forwarding DNS records. Previous mail records remain only in customer notes. Sender-verification DNS is not removed.</p>
            <form method="post" action="index.php?m=forward_email&amp;service_id={$serviceId|escape}">
                <input type="hidden" name="token" value="{$token|escape}">
                <input type="hidden" name="action" value="disable">
                <label for="forward-email-disable">Enter <code>{$domain|escape}</code> to confirm:</label>
                <input id="forward-email-disable" class="form-control" name="confirm_disable" required autocomplete="off">
                <button class="btn btn-danger" type="submit" style="margin-top:10px">Disable and Delete</button>
            </form>
        </details>
    {/if}
{/if}
