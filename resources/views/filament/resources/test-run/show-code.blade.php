<div style="text-align:center;">
    <div style="width:14rem; height:14rem; margin:0 auto; background:#fff; padding:0.5rem; border-radius:0.5rem;">
        <div style="width:100%; height:100%;">{!! str_replace('<svg ', '<svg style="width:100%;height:100%;display:block;" ', $qr) !!}</div>
    </div>
    <div style="font-family:ui-monospace,monospace; font-size:2rem; font-weight:700; letter-spacing:0.15em; margin-top:1rem;">
        {{ $code }}
    </div>
    <div style="font-size:0.85rem; opacity:0.7; margin-top:0.5rem;">Status: {{ $status }}</div>
    <div style="font-size:0.8rem; opacity:0.6; margin-top:0.25rem; word-break:break-all;">{{ $url }}</div>
</div>
