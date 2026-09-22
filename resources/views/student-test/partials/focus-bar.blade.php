{{-- Sticky-Kopfleiste der Fokus-Ansicht: Restzeit, Fortschritt, Auto-Weiter --}}
<header class="bar" id="bar">
    <span class="timer"><span class="timer-label">{{ $timerLabel ?? 'Restzeit' }}</span><span id="timer">{{ intdiv($remaining, 60) }}:{{ str_pad((string) ($remaining % 60), 2, '0', STR_PAD_LEFT) }}</span></span>
    <span class="progress"><span id="progress">0 / {{ $total }}</span><span class="long"> beantwortet</span></span>
    <label class="auto">
        <input type="checkbox" id="autoscroll" checked>
        <span class="long">Automatisch weiter</span><span class="short">Auto-weiter</span>
    </label>
    <div class="save-status" id="save-status" hidden></div>
</header>
