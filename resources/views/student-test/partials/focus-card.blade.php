{{-- Eine Aussage als Fokus-Karte. $given = bereits gespeicherte Antwort, $correct nur in der Übung. --}}
<section class="q-card question {{ $given ? 'answered' : '' }}" data-qid="{{ $q->id }}"
         @isset($correct) data-correct="{{ $correct }}" @endisset>
    <div class="q-num">Satz {{ $index + 1 }} von {{ $total }}</div>
    <div class="q-body">
        <p class="q-text">{{ $q->question_text }}</p>
        <div class="feedback" aria-live="polite"></div>
    </div>
    <div class="q-actions">
        <button type="button" class="btn btn-r btn-answer {{ $given === 'richtig' ? 'active' : '' }}" data-answer="richtig">richtig</button>
        <button type="button" class="btn btn-f btn-answer {{ $given === 'falsch' ? 'active' : '' }}" data-answer="falsch">falsch</button>
    </div>
</section>
