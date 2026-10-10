<div class="text-center">
    <div id="server-installing">

    </div>
    {*
    Status types:
    installing,
    install_failed,
    reinstall_failed,
    suspended,
    restoring_backup
    *}
    {if $serverData['status'] == ''}
        <div id="server-installed">
            <p class="margin-top-bottom">
                <a href="{$serviceUrl}" target="_blank" class="btn btn-default">{$LANG.GOTO_PANEL}</a>
            </p>
            <div class="row mt-2 mb-5">
                <div class="col">
                    <label for="change-text-id">
                        {$LANG.SERVER_ID}
                    </label>
                    <input
                            class="form-control text-center copy-text"
                            type="text"
                            id="change-text-id"
                            data-id="change-text-id"
                            data-new-text="{$LANG.ID_COPIED}"
                            data-clipboard-text="{$serverId}"
                            value="{$serverId}"
                            readonly>
                </div>
                <div class="col">
                    <label for="change-text-ip">
                        {$LANG.SERVER_IP}
                    </label>
                    <input
                            class="form-control text-center copy-text"
                            id="change-text-ip"
                            data-id="change-text-ip"
                            data-new-text="{$LANG.IP_COPIED}"
                            data-clipboard-text="{$serverIp}"
                            value="{$serverIp}" readonly>
                </div>
            </div>

        </div>
    {/if}
</div>