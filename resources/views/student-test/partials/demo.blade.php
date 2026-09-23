{{--
    Animierte Vorführung „So funktioniert der Test“ (ersetzt Probeaufgaben).
    Reines HTML/CSS/JS, kein Video. Die Beispielsätze sind erfunden und stammen
    bewusst NICHT aus den Fragebögen. Die Vorführung ist nicht bedienbar
    (aria-hidden); der <details>-Block ist die Textalternative.
--}}
<section class="demo" id="demo" aria-label="So funktioniert der Test">
    <h2 class="demo-title">So funktioniert der Test</h2>

    <div class="demo-stage">
        <div class="demo-device" id="demo-device" aria-hidden="true">
            <div class="demo-bar" data-demo="bar">
                <span class="demo-timer"><span class="demo-timer-label">Restzeit</span><span data-demo="timer">3:00</span></span>
                <span class="demo-progress"><span data-demo="progress">0</span> / 3</span>
            </div>
            <div class="demo-viewport">
                <div class="demo-track" data-demo="track">
                    @foreach(['Eis ist kalt.', 'Ein Hund hat Flügel.', 'Äpfel wachsen an Bäumen.'] as $i => $sentence)
                        <div class="demo-card" data-demo="card">
                            <div class="demo-num">Satz {{ $i + 1 }} von 3</div>
                            <p class="demo-text">{{ $sentence }}</p>
                            <div class="demo-actions">
                                <span class="demo-btn demo-r" data-answer="richtig">richtig</span>
                                <span class="demo-btn demo-f" data-answer="falsch">falsch</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="demo-finger" data-demo="finger"></div>
        </div>

        <div class="demo-guide" aria-hidden="true">
            <div class="demo-bubble" data-demo="bubble"><span data-demo="say">Schau mal, so geht’s!</span></div>
            <div class="demo-owl">🦉</div>
        </div>
    </div>

    <div class="demo-controls">
        <button type="button" class="btn btn-secondary" data-demo="skip">Überspringen</button>
        <button type="button" class="btn btn-secondary" data-demo="replay" hidden>↻ Nochmal ansehen</button>
    </div>

    <details class="demo-steps" data-demo="steps">
        <summary>Anleitung als Text</summary>
        <ol>
            <li>Lies den Satz genau, z.&nbsp;B. „Eis ist kalt.“</li>
            <li>Stimmt der Satz, tippst du auf <b>richtig</b>. Stimmt er nicht, tippst du auf <b>falsch</b>.</li>
            <li>Danach kommt automatisch der nächste Satz.</li>
            <li>Vertippt? Scrolle zurück zum Satz und tippe die andere Antwort. Die neue Antwort zählt.</li>
            <li>Oben siehst du die Restzeit und wie viele Sätze du schon beantwortet hast.</li>
            <li>Du musst nicht alle Sätze schaffen. Wenn die Zeit um ist, endet der Test von selbst.</li>
        </ol>
    </details>
</section>

<style>
    .demo { margin:1.25rem 0; padding:1rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; }
    .demo-title { margin:0 0 .75rem; text-align:center; }
    .demo-stage { display:flex; flex-direction:column-reverse; align-items:center; gap:.75rem; }
    @media (min-width: 640px) { .demo-stage { flex-direction:row; justify-content:center; align-items:center; gap:1.25rem; } }

    .demo-device { position:relative; width:100%; max-width:300px; flex:none; background:#f1f5f9; border:6px solid #0f172a;
                   border-radius:22px; overflow:hidden; box-shadow:0 6px 18px rgba(15,23,42,.18); user-select:none; }
    .demo-bar { display:flex; justify-content:space-between; align-items:center; padding:.45rem .75rem; background:#1e3a8a; color:#fff;
                font-variant-numeric:tabular-nums; transition:box-shadow .3s; }
    .demo-bar.hl { box-shadow:inset 0 0 0 3px #fbbf24; }
    .demo-timer { font-weight:700; }
    .demo-timer-label { font-size:.7rem; opacity:.8; font-weight:400; margin-right:.3rem; }
    .demo-progress { font-size:.85rem; opacity:.9; }
    .demo-viewport { height:236px; overflow:hidden; }
    .demo-track { padding:0 .6rem; transition:transform .6s ease-in-out; }
    .demo-card { height:216px; margin:10px 0; padding:.9rem; background:#fff; border-radius:12px; box-shadow:0 1px 4px rgba(15,23,42,.08);
                 display:flex; flex-direction:column; justify-content:center; gap:.9rem; }
    .demo-num { font-size:.75rem; color:#64748b; text-align:center; }
    .demo-text { margin:0; font-size:1.2rem; font-weight:500; text-align:center; }
    .demo-actions { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
    .demo-btn { display:flex; align-items:center; justify-content:center; min-height:2.6rem; border-radius:10px; color:#fff; font-weight:600;
                transition:opacity .25s, box-shadow .25s; }
    .demo-r { background:#16a34a; }
    .demo-f { background:#dc2626; }
    .demo-btn.active { box-shadow:0 0 0 3px #fff, 0 0 0 5px #0f172a; }
    .demo-card.answered .demo-btn:not(.active) { opacity:.35; }

    .demo-finger { position:absolute; left:50%; top:60%; width:34px; height:34px; margin:-17px 0 0 -17px; border-radius:50%;
                   background:rgba(15,23,42,.35); border:3px solid #fff; box-shadow:0 2px 6px rgba(15,23,42,.35);
                   opacity:0; pointer-events:none; transition:left .6s ease-in-out, top .6s ease-in-out, opacity .3s, transform .15s; }
    .demo-finger.show { opacity:1; }
    .demo-finger.press { transform:scale(.8); background:rgba(15,23,42,.55); }
    .demo-finger.tap::after { content:""; position:absolute; inset:-3px; border-radius:50%; border:3px solid #fff; animation:demo-ripple .5s ease-out; }
    @keyframes demo-ripple { from { transform:scale(1); opacity:1; } to { transform:scale(2.2); opacity:0; } }

    .demo-guide { display:flex; align-items:flex-end; gap:.5rem; width:100%; max-width:300px; }
    @media (min-width: 640px) { .demo-guide { flex-direction:column; align-items:center; max-width:230px; } }
    .demo-owl { font-size:2.6rem; line-height:1; flex:none; }
    .demo-bubble { position:relative; flex:1; min-height:4.5rem; display:flex; align-items:center; padding:.7rem .9rem; background:#fff;
                   border:2px solid #1e3a8a; border-radius:14px; font-weight:600; line-height:1.35; }
    .demo-bubble::after { content:""; position:absolute; right:-9px; bottom:14px; width:14px; height:14px; background:#fff;
                          border-right:2px solid #1e3a8a; border-bottom:2px solid #1e3a8a; transform:rotate(-45deg); }
    @media (min-width: 640px) {
        .demo-bubble { width:100%; }
        .demo-bubble::after { right:auto; left:calc(50% - 7px); bottom:-9px; transform:rotate(45deg); }
    }
    .demo-bubble span { transition:opacity .25s; }
    .demo-bubble.fade span { opacity:0; }

    .demo-controls { display:flex; justify-content:center; gap:.5rem; margin-top:.75rem; }
    .demo-steps { margin-top:.75rem; font-size:.95rem; }
    .demo-steps summary { cursor:pointer; color:#475569; }
    @media (prefers-reduced-motion: reduce) {
        .demo-track, .demo-finger, .demo-btn, .demo-bubble span { transition:none; }
        .demo-finger { display:none; }
    }
</style>

<script>
(function () {
    const root = document.getElementById('demo');
    if (!root) return;
    const $ = (n) => root.querySelector('[data-demo="' + n + '"]');
    const device = document.getElementById('demo-device');
    const track = $('track'), finger = $('finger'), bubble = $('bubble'), say = $('say');
    const timerEl = $('timer'), progressEl = $('progress'), bar = $('bar');
    const skipBtn = $('skip'), replayBtn = $('replay');
    const cards = Array.from(root.querySelectorAll('[data-demo="card"]'));
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let token = 0, clock = null, seconds = 180;

    const wait = (ms) => { const t = token; return new Promise((res, rej) => setTimeout(() => (t === token ? res() : rej('abgebrochen')), ms)); };

    function speak(text) {
        bubble.classList.add('fade');
        setTimeout(() => { say.textContent = text; bubble.classList.remove('fade'); }, reduced ? 0 : 200);
    }
    function showCard(i) { track.style.transform = 'translateY(' + (cards[0].offsetTop - cards[i].offsetTop) + 'px)'; }
    function btn(i, answer) { return cards[i].querySelector('[data-answer="' + answer + '"]'); }
    function answer(i, value) {
        cards[i].classList.add('answered');
        cards[i].querySelectorAll('.demo-btn').forEach((b) => b.classList.toggle('active', b.dataset.answer === value));
        progressEl.textContent = String(root.querySelectorAll('.demo-card.answered').length);
    }
    function point(el, dy) {
        const d = device.getBoundingClientRect(), r = el.getBoundingClientRect();
        finger.style.left = (r.left - d.left + r.width / 2) + 'px';
        finger.style.top = (r.top - d.top + r.height / 2 + (dy || 0)) + 'px';
        finger.classList.add('show');
    }
    function renderClock() { timerEl.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0'); }

    async function tap(el, dy) {
        point(el, dy); await wait(750);
        finger.classList.add('press', 'tap'); await wait(180);
        finger.classList.remove('press'); await wait(350);
        finger.classList.remove('tap', 'show');
    }
    // Wischen: Finger drückt auf die Karte und zieht sie mit (zurück = nach unten)
    async function swipe(from, to) {
        point(cards[from].querySelector('.demo-text')); await wait(750);
        finger.classList.add('press'); await wait(150);
        const d = device.getBoundingClientRect(), r = cards[from].querySelector('.demo-text').getBoundingClientRect();
        finger.style.top = (r.top - d.top + r.height / 2 + (to < from ? 110 : -110)) + 'px';
        showCard(to); await wait(650);
        finger.classList.remove('press', 'show');
    }

    function reset() {
        token++;
        clearInterval(clock);
        seconds = 180; renderClock();
        cards.forEach((c) => { c.classList.remove('answered'); c.querySelectorAll('.demo-btn').forEach((b) => b.classList.remove('active')); });
        progressEl.textContent = '0';
        bar.classList.remove('hl');
        finger.classList.remove('show', 'press', 'tap');
        track.style.transition = 'none'; showCard(0); void track.offsetHeight; track.style.transition = '';
    }

    function finish() {
        token++;
        clearInterval(clock);
        answer(0, 'richtig'); answer(1, 'falsch'); answer(2, 'richtig');
        showCard(2);
        bar.classList.remove('hl');
        finger.classList.remove('show', 'press', 'tap');
        speak('Du musst nicht alle Sätze schaffen. Bereit? Dann tippe unten auf „Test starten“.');
        skipBtn.hidden = true; replayBtn.hidden = false;
    }

    async function play() {
        reset();
        skipBtn.hidden = false; replayBtn.hidden = true;
        clock = setInterval(() => { if (seconds > 0) { seconds--; renderClock(); } }, 1000);
        try {
            speak('Hallo! Ich zeige dir kurz, wie der Test geht.'); await wait(2400);
            speak('Lies den Satz genau: „Eis ist kalt.“'); await wait(2400);
            speak('Das stimmt! Also tippst du auf „richtig“.'); await wait(1200);
            await tap(btn(0, 'richtig')); answer(0, 'richtig'); await wait(900);
            speak('Dann kommt von selbst der nächste Satz.'); showCard(1); await wait(2400);
            speak('„Ein Hund hat Flügel.“ Hoppla …'); await wait(1400);
            await tap(btn(1, 'richtig')); answer(1, 'richtig'); await wait(700);
            showCard(2); await wait(900);
            speak('Ups! Beim Hund aus Versehen „richtig“ getippt. Das stimmt ja gar nicht!'); await wait(2800);
            speak('Kein Problem: Einfach zurückscrollen …'); await wait(900);
            await swipe(2, 1); await wait(600);
            speak('… und die andere Antwort tippen. Die neue Antwort zählt.'); await wait(1000);
            await tap(btn(1, 'falsch')); answer(1, 'falsch'); await wait(2200);
            speak('Weiter geht’s mit dem nächsten Satz.'); await wait(900);
            await swipe(1, 2); await wait(400);
            speak('„Äpfel wachsen an Bäumen.“ Stimmt!'); await wait(1500);
            await tap(btn(2, 'richtig')); answer(2, 'richtig'); await wait(1200);
            speak('Oben siehst du die Restzeit und wie viele Sätze du schon hast.');
            bar.classList.add('hl'); point(bar); await wait(3200);
            finish();
        } catch (e) { /* abgebrochen (Überspringen/Neustart) */ }
    }

    skipBtn.addEventListener('click', finish);
    replayBtn.addEventListener('click', play);

    if (reduced) {
        $('steps').open = true;
        finish();
    } else {
        setTimeout(play, 600);
    }
})();
</script>
