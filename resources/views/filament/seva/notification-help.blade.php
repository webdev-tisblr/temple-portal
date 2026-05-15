<div class="text-sm text-gray-600 dark:text-gray-300 space-y-2 mt-1">
    <p>
        Message content + channels + recipients are managed centrally in
        <strong>Communication → Notification Templates</strong>.
    </p>
    <p>
        For each event below you can create one template per audience (e.g.
        one for the devotee, one for the pujari role, one for a fixed
        coordinator email). All enabled templates fire on every dispatch:
    </p>
    <ul class="list-disc ml-5 space-y-0.5">
        <li><code class="text-xs">seva.booking.confirmed</code> — fires once at payment capture</li>
        <li><code class="text-xs">seva.booking.reminder</code> — fires at each offset below</li>
    </ul>
    <p>
        This section only controls <strong>WHEN</strong> reminders fire — the
        per-recipient configuration lives on each template.
    </p>
</div>
