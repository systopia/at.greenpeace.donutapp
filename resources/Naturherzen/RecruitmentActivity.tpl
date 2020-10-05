{*-------------------------------------------------------+
| DonutApp Processor for Naturherzen                     |
| Copyright (C) 2020 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+-------------------------------------------------------*}

<p>Kontakt '{$contact.display_name}' wurde erfasst von Werber '{$submission.fundraiser_name}'.</p>
<p>SEPA Mandat <code>{$mandate.reference}</code> wird {$rcontribution.amount|crmMoney:$rcontribution.currency} alle {$rcontribution.frequency_interval} Monate einziehen.</p>
{if $todos}
<p>Noch zu erledigen:
    <ul>
    {foreach from=$todos item=todo}
        <li>{$todo}</li>
    {/foreach}
    </ul>
</p>
{/if}
