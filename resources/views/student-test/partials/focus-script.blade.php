{{--
    Gemeinsame Logik der Fokus-Ansicht.
    Erwartet: $mode ('test'|'practice'), $remaining (Sekunden).
    Test-Modus: Antworten per AJAX speichern (mit Retry), Timer-Ablauf → Server-Abgabe.
    Übungs-Modus: nichts speichern, sofortige Rückmeldung, Timer-Ablauf → Übung beenden.
--}}
<script>
(function () {
    const MODE = @json($mode);
    const answerUrl = @json(route('student-test.answer'));
    const questionsUrl = @json(route('student-test.questions'));
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const bar = document.getElementById('bar');
    const timerEl = document.getElementById('timer');
    const progressEl = document.getElementById('progress');
    const saveStatus = document.getElementById('save-status');
    const autoBox = document.getElementById('autoscroll');
    const finalOpen = document.getElementById('final-open');
    const cards = Array.from(document.querySelectorAll('.q-card'));
    const questionCards = cards.filter(c => c.classList.contains('question'));
    const landscape = window.matchMedia('(orientation: landscape) and (min-width: 768px) and (min-height: 500px)');
    const smooth = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';

    // Kartenhöhe richtet sich nach der echten Höhe der Kopfleiste (bricht ggf. um)
    function syncBarHeight() {
        document.documentElement.style.setProperty('--bar-h', bar.offsetHeight + 'px');
    }
    syncBarHeight();
    if ('ResizeObserver' in window) new ResizeObserver(syncBarHeight).observe(bar);

    // ── Auto-Weiter (Voreinstellung: an, pro Gerät gemerkt) ──────────────
    const AUTO_KEY = 'lsp.autoscroll';
    try {
        const saved = localStorage.getItem(AUTO_KEY);
        if (saved !== null) autoBox.checked = saved === '1';
    } catch (e) { /* kein Storage verfügbar */ }
    autoBox.addEventListener('change', () => {
        try { localStorage.setItem(AUTO_KEY, autoBox.checked ? '1' : '0'); } catch (e) { /* egal */ }
    });

    // ── Aktive Karte = die in der Bildschirmmitte ────────────────────────
    function setActive(card) {
        cards.forEach(c => c.classList.toggle('is-active', c === card));
    }
    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) setActive(e.target); });
    }, { rootMargin: '-45% 0px -45% 0px' });
    cards.forEach(c => observer.observe(c));

    // Es gibt immer nur EIN ausstehendes automatisches Scrollen. Jede neue
    // Antwort und jede Berührung durch den Schüler bricht ältere Timer ab –
    // sonst kann ein veralteter Timer auf eine schon gelöste Karte zurückspringen.
    let advanceTimer = null;
    let fallbackTimer = null;
    function cancelAutoScroll() {
        clearTimeout(advanceTimer);
        clearTimeout(fallbackTimer);
        advanceTimer = fallbackTimer = null;
    }
    ['touchstart', 'wheel'].forEach(ev =>
        window.addEventListener(ev, () => clearTimeout(fallbackTimer), { passive: true }));

    // Liegt die Karte auf der Mittellinie des sichtbaren Bereichs (unter der Leiste)?
    function isCentered(card) {
        const r = card.getBoundingClientRect();
        const mid = (window.innerHeight + bar.offsetHeight) / 2;
        return r.top <= mid && r.bottom >= mid;
    }

    // Solange wir selbst scrollen, greift das eigene Einrasten nicht
    let programmaticUntil = 0;

    function center(card, behavior) {
        if (!card) return;
        const b = behavior || smooth;
        clearTimeout(fallbackTimer);
        clearTimeout(snapTimer);
        setActive(card);
        programmaticUntil = performance.now() + (b === 'smooth' ? 900 : 150);
        const startY = window.scrollY;
        card.scrollIntoView({ block: 'center', behavior: b });
        if (b === 'smooth') {
            // Fallback nur, wenn der Browser das weiche Scrollen gar nicht ausgeführt hat
            fallbackTimer = setTimeout(() => {
                fallbackTimer = null;
                if (Math.abs(window.scrollY - startY) < 2 && !isCentered(card)) {
                    card.scrollIntoView({ block: 'center' });
                }
            }, 800);
        }
    }
    // Eigenes Einrasten statt CSS-Scroll-Snap (siehe layout.blade.php): nach manuellem
    // Scrollen – Finger losgelassen, Scrollen zur Ruhe gekommen – die Karte in der
    // Mitte zentrieren. Automatisches Weiterscrollen hat immer Vorrang.
    let snapTimer = null;
    let touching = false;
    function scheduleSnap() {
        clearTimeout(snapTimer);
        snapTimer = setTimeout(snapToNearest, 180);
    }
    function snapToNearest() {
        if (touching || advanceTimer || performance.now() < programmaticUntil) return;
        const mid = (window.innerHeight + bar.offsetHeight) / 2;
        let best = null;
        let bestDist = Infinity;
        cards.forEach(c => {
            const r = c.getBoundingClientRect();
            const d = Math.abs((r.top + r.bottom) / 2 - mid);
            if (d < bestDist) { bestDist = d; best = c; }
        });
        if (!best) return;
        if (bestDist > 6) {
            center(best);
        } else {
            setActive(best);
        }
    }
    window.addEventListener('touchstart', () => { touching = true; clearTimeout(snapTimer); }, { passive: true });
    ['touchend', 'touchcancel'].forEach(ev => window.addEventListener(ev, () => {
        touching = false;
        scheduleSnap();
    }, { passive: true }));
    window.addEventListener('scroll', () => {
        if (performance.now() < programmaticUntil) return;
        scheduleSnap(); // läuft nach dem letzten Scroll-Ereignis (auch nach Schwung-Scrollen)
    }, { passive: true });

    function scheduleNext(card, delay) {
        cancelAutoScroll();
        advanceTimer = setTimeout(() => {
            advanceTimer = null;
            center(cards[cards.indexOf(card) + 1]);
        }, delay);
    }

    // Tablet quer: Tipp auf eine unscharfe Nachbar-Karte holt sie in die Mitte.
    // Maßgeblich ist die tatsächliche Position – nicht die „aktiv“-Markierung,
    // die während einer Scroll-Animation schon der Zielkarte gehört.
    cards.forEach(card => {
        card.addEventListener('click', e => {
            if (landscape.matches && !isCentered(card)) {
                e.preventDefault();
                e.stopPropagation();
                cancelAutoScroll();
                center(card);
            }
        }, true);
    });

    function updateProgress() {
        const answered = questionCards.filter(c => c.classList.contains('answered')).length;
        progressEl.textContent = answered + ' / ' + questionCards.length;
        if (finalOpen) {
            const open = questionCards.length - answered;
            finalOpen.textContent = open === 0
                ? 'Du hast alle Sätze beantwortet.'
                : (open === 1 ? 'Ein Satz ist noch ohne Antwort.' : open + ' Sätze sind noch ohne Antwort.');
        }
    }

    // ── Speichern (nur Test-Modus) ───────────────────────────────────────
    // Noch nicht vom Server bestätigte Antworten (question_id → Antwort).
    // Netzwerkfehler, 429 und 5xx werden automatisch wiederholt, damit keine
    // Antwort still verloren geht. Karten mit offener Antwort sind markiert.
    const pending = new Map();
    const inFlight = new Set();
    let hadFailure = false;
    let sessionLost = false;

    function updateStatus() {
        if (sessionLost) {
            saveStatus.textContent = 'Sitzung abgelaufen – bitte gib deiner Lehrkraft Bescheid.';
            saveStatus.hidden = false;
        } else if (hadFailure && pending.size > 0) {
            saveStatus.textContent = 'Verbindung wackelt – Antworten werden gespeichert …';
            saveStatus.hidden = false;
        } else {
            saveStatus.hidden = true;
            if (pending.size === 0) hadFailure = false;
        }
    }

    async function send(qid, retryCount = 0) {
        if (inFlight.has(qid) || sessionLost || !pending.has(qid)) return;
        const answer = pending.get(qid);
        inFlight.add(qid);
        let retry = false;
        let ended = false;
        try {
            const res = await fetch(answerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ question_id: parseInt(qid, 10), answer: answer }),
            });
            if (res.status === 401 || res.status === 419) {
                sessionLost = true;
            } else if (res.ok || res.status === 422) {
                // gespeichert – oder nicht speicherbar (z. B. Test schon abgegeben): nicht wiederholen
                if (pending.get(qid) === answer) pending.delete(qid);
                if (res.ok) {
                    const data = await res.json().catch(() => ({}));
                    ended = data.ended === true;
                }
            } else {
                retry = true; // 429, 5xx
            }
        } catch (e) {
            retry = true; // Netzwerkfehler
        } finally {
            inFlight.delete(qid);
        }

        if (ended) {
            // Zeit abgelaufen oder von der Lehrkraft beendet → Server entscheidet
            window.location = questionsUrl;
            return;
        }

        const card = document.querySelector('.question[data-qid="' + qid + '"]');
        if (!pending.has(qid)) {
            card?.classList.remove('unsaved');
        } else if (retry) {
            hadFailure = true;
            setTimeout(() => send(qid, retryCount + 1), Math.min(1000 * 2 ** retryCount, 10000));
        } else if (!sessionLost) {
            send(qid); // während des Requests umentschieden → neuen Stand senden
        }
        updateStatus();
    }

    // Wartet (begrenzt), bis alle offenen Antworten gespeichert sind.
    function flushAnswers(maxMs) {
        pending.forEach((_, qid) => send(qid));
        const started = Date.now();
        return new Promise(resolve => {
            (function check() {
                if (pending.size === 0 || sessionLost || Date.now() - started >= maxMs) return resolve();
                setTimeout(check, 200);
            })();
        });
    }

    // ── Antworten ────────────────────────────────────────────────────────
    function practiceFeedback(card, answer) {
        const fb = card.querySelector('.feedback');
        const ok = answer === card.dataset.correct;
        fb.textContent = ok ? 'Richtig!' : 'Leider falsch – der Satz ist ' + card.dataset.correct + '.';
        fb.classList.toggle('good', ok);
        fb.classList.toggle('bad', !ok);
    }

    questionCards.forEach(card => {
        const qid = card.dataset.qid;
        card.querySelectorAll('.btn-answer').forEach(btn => {
            btn.addEventListener('click', () => {
                if (document.body.classList.contains('locked')) return;
                card.querySelectorAll('.btn-answer').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                card.classList.add('answered');
                updateProgress();

                if (MODE === 'test') {
                    card.classList.add('unsaved');
                    pending.set(qid, btn.dataset.answer);
                    send(qid);
                } else {
                    practiceFeedback(card, btn.dataset.answer);
                }

                if (autoBox.checked) {
                    scheduleNext(card, MODE === 'practice' ? 1200 : 300);
                } else {
                    cancelAutoScroll();
                }
            });
        });
    });

    // ── Timer ────────────────────────────────────────────────────────────
    let remaining = {{ (int) $remaining }};
    function renderTimer() {
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        timerEl.textContent = m + ':' + String(s).padStart(2, '0');
        bar.classList.toggle('warning', MODE === 'test' && remaining <= 30);
    }

    async function onExpire() {
        if (MODE === 'test') {
            await flushAnswers(5000);
            window.location = questionsUrl;
        } else {
            document.body.classList.add('locked');
            const final = cards[cards.length - 1];
            final.querySelector('[data-expired]')?.removeAttribute('hidden');
            cancelAutoScroll();
            center(final);
        }
    }

    renderTimer();
    if (remaining <= 0) {
        onExpire();
    } else {
        const tick = setInterval(() => {
            remaining--;
            renderTimer();
            if (remaining <= 0) {
                clearInterval(tick);
                onExpire();
            }
        }, 1000);
    }

    // ── Abgabe (Test-Modus) ──────────────────────────────────────────────
    const submitForm = document.getElementById('submit-form');
    submitForm?.addEventListener('submit', async (event) => {
        if (pending.size === 0) return;
        event.preventDefault();
        submitForm.querySelector('button').disabled = true;
        await flushAnswers(8000);
        submitForm.submit();
    });

    // ── Start: zur ersten offenen Aussage (z. B. nach erneutem Login) ────
    updateProgress();
    const firstOpen = questionCards.find(c => !c.classList.contains('answered')) || cards[cards.length - 1];
    if (firstOpen && firstOpen !== questionCards[0]) {
        center(firstOpen, 'auto');
    } else {
        center(cards[0], 'auto');
    }
})();
</script>
