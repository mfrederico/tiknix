<?php
/**
 * The marketing pages' shared stylesheet (landing, stories): one design, one place.
 * Needs $logoV (the logo's filemtime, for the mask URL cache-buster).
 */
?>
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/icon-512.png" type="image/png" sizes="512x512">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <style>
        *{ margin:0; padding:0; box-sizing:border-box; }
        :root{
            --bg2:#060d20; --bg1:#0b1530; --panel:#0e1a38;
            --text:#eaedf5; --soft:#9ba4bd; --dim:#6b7593;
            --accent:#3b76f0; --accent2:#6b97f6; --good:#3ddc97;
            --line:rgba(255,255,255,0.08); --line2:rgba(255,255,255,0.14);
            --serif:'Playfair Display', Georgia, serif;
            --sans:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
            --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
        }
        html{ background:var(--bg2); scroll-behavior:smooth; }
        body{
            font-family:var(--sans); color:var(--text); line-height:1.5;
            background:
                radial-gradient(1200px 640px at 50% -6%, rgba(59,118,240,0.20), transparent 60%),
                linear-gradient(180deg, var(--bg1) 0%, var(--bg2) 46%);
            -webkit-font-smoothing:antialiased;
        }
        a{ color:var(--accent2); text-decoration:none; }
        a:hover{ color:#9db8fb; }
        h1,h2,h3{ font-family:var(--serif); font-weight:600; letter-spacing:-0.01em; line-height:1.12; }
        .container{ width:min(1180px, 92vw); margin:0 auto; }
        .btn{ display:inline-flex; align-items:center; gap:9px; padding:14px 24px; border-radius:11px;
              font-weight:600; font-size:16px; transition:transform .1s ease, box-shadow .15s ease; }
        .btn:active{ transform:translateY(1px); }
        .btn-primary{ background:var(--accent); color:#fff; box-shadow:0 8px 26px rgba(59,118,240,0.4); }
        .btn-primary:hover{ color:#fff; }
        .btn-ghost{ border:1px solid var(--line2); color:var(--text); }
        .btn-ghost:hover{ color:var(--text); border-color:var(--soft); }
        .eyebrow{ font-size:13px; letter-spacing:0.14em; text-transform:uppercase; color:var(--accent2); font-weight:600; }
        .card{ border:1px solid var(--line); border-radius:16px; background:rgba(255,255,255,0.02); }
        .logo{ display:flex; align-items:center; gap:11px; color:var(--text); }
        .logo-mark{ width:32px; height:32px; flex:0 0 auto; background:currentColor;
            -webkit-mask:url(/img/tiknix.svg?v=<?= $logoV ?>) center/contain no-repeat;
                    mask:url(/img/tiknix.svg?v=<?= $logoV ?>) center/contain no-repeat; }
        .logo-word{ font-family:var(--serif); font-size:23px; font-weight:700; letter-spacing:-0.02em; }

        /* nav */
        .nav{ display:flex; align-items:center; justify-content:space-between; padding:22px 0; }
        .nav-links{ display:flex; align-items:center; gap:30px; font-size:15px; }
        .nav-links a{ color:var(--soft); } .nav-links a:hover{ color:var(--text); }
        .nav-cta{ padding:9px 18px; border-radius:9px; background:var(--accent); color:#fff !important; font-weight:600;
                  box-shadow:0 4px 18px rgba(59,118,240,0.36); }

        /* hero */
        .hero{ display:grid; grid-template-columns:1.05fr 0.95fr; gap:3.5rem; align-items:center;
               padding:56px 0 92px; }
        .hero h1{ font-size:clamp(34px, 4.4vw, 54px); text-wrap:pretty; }
        .hero .sub{ margin-top:22px; font-size:clamp(16px, 1.6vw, 18px); line-height:1.6; color:var(--soft); max-width:540px; }
        .hero-cta{ display:flex; flex-wrap:wrap; gap:14px; margin-top:32px; }
        .hero-fine{ margin-top:20px; font-size:14px; color:var(--dim); }
        .pill{ display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border:1px solid var(--line2);
               border-radius:999px; font-size:13px; color:var(--soft); }
        .dot{ width:6px; height:6px; border-radius:999px; background:var(--good); box-shadow:0 0 8px var(--good); }

        /* hero visual */
        .frame{ border:1px solid var(--line2); border-radius:16px; overflow:hidden;
                background:linear-gradient(180deg,#12203f,#0c1730); box-shadow:0 30px 70px rgba(0,0,0,0.5); }
        .frame-bar{ display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid var(--line); }
        .tl{ width:11px; height:11px; border-radius:999px; display:inline-block; }
        .url{ font-family:var(--mono); font-size:12.5px; color:var(--soft); }
        .badge-iso{ display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--good);
                    border:1px solid rgba(61,220,151,0.4); border-radius:999px; padding:4px 10px; }
        .stat{ border:1px solid var(--line); border-radius:10px; padding:14px; background:rgba(255,255,255,0.02); }
        .stat .k{ font-size:12px; color:var(--dim); }
        .stat .v{ font-size:24px; font-weight:700; font-family:var(--serif); margin-top:4px; }
        .chip{ display:inline-flex; align-items:center; gap:7px; font-size:12.5px; color:var(--soft);
               border:1px solid var(--line2); border-radius:8px; padding:7px 11px; }
        .frame-foot{ display:flex; align-items:center; gap:9px; padding:13px 22px; border-top:1px solid var(--line);
                     background:rgba(59,118,240,0.08); font-family:var(--mono); font-size:12.5px; color:var(--accent2); }

        /* generic bands + grids */
        .band{ padding:64px 0; border-top:1px solid var(--line); }
        .band-head{ text-align:center; margin-bottom:44px; }
        .band-head h2{ font-size:clamp(28px, 3.2vw, 38px); margin-top:14px; }
        .band-head p{ font-size:16px; color:var(--soft); margin-top:14px; }
        .grid-3{ display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:22px; }
        .grid-2{ display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:22px; }
        .ic{ width:46px; height:46px; border-radius:12px; background:rgba(59,118,240,0.14);
             display:flex; align-items:center; justify-content:center; color:var(--accent2); }
        .pcard{ padding:30px; }
        .pcard h3{ font-size:22px; margin-top:20px; }
        .pcard p{ font-size:15px; line-height:1.6; color:var(--soft); margin-top:11px; }
        .step-n{ font-family:var(--serif); font-size:34px; font-weight:700; color:var(--accent2); }

        /* integrations */
        .int-row{ display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:14px; }
        .int{ display:inline-flex; align-items:center; gap:9px; padding:11px 20px; border:1px solid var(--line2);
              border-radius:11px; font-size:15px; font-weight:600; color:var(--text); }
        .int .d{ width:8px; height:8px; border-radius:999px; }

        /* showcase */
        .scard{ overflow:hidden; display:block; color:inherit; transition:transform .15s ease, border-color .15s ease; }
        .scard:hover{ transform:translateY(-4px); border-color:var(--line2); color:inherit; }
        .shot{ height:150px; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .shot img{ width:100%; height:100%; object-fit:cover; }
        .scard .body{ padding:20px; }
        .scard .t{ font-family:var(--serif); font-size:18px; font-weight:600; }
        .scard .b{ font-size:14px; color:var(--soft); margin-top:8px; line-height:1.55; }

        /* pricing */
        .price-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:22px; max-width:840px; margin:0 auto; }
        .plan{ padding:34px; position:relative; }
        .plan .amt{ font-family:var(--serif); font-size:52px; font-weight:700; }
        .plan .lbl{ font-size:15px; color:var(--soft); font-weight:600; }
        .plan .fine{ font-size:14px; color:var(--dim); margin-top:6px; }
        .plan .feat{ display:flex; flex-direction:column; gap:12px; font-size:15px; color:var(--soft); }
        .plan .feat > div{ display:flex; gap:11px; }
        .ck{ color:var(--good); }
        .plan-hi{ border:1px solid var(--accent); background:linear-gradient(180deg, rgba(59,118,240,0.12), rgba(59,118,240,0.03)); }
        .ribbon{ position:absolute; top:-12px; right:26px; font-size:12px; font-weight:700; letter-spacing:0.04em;
                 background:var(--accent); color:#fff; padding:5px 12px; border-radius:999px; }
        .hr{ height:1px; background:var(--line); margin:22px 0; }

        /* faq */
        .faq-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:22px; max-width:980px; margin:0 auto; }
        .qa{ padding:26px; }
        .qa h3{ font-size:18px; font-family:var(--sans); font-weight:700; }
        .qa p{ font-size:15px; line-height:1.6; color:var(--soft); margin-top:10px; }
        .code{ font-family:var(--mono); color:var(--accent2); }

        /* final + footer */
        .final{ text-align:center; padding:96px 0;
                background:radial-gradient(900px 400px at 50% 120%, rgba(59,118,240,0.22), transparent 60%); }
        .final h2{ font-size:clamp(30px, 4vw, 46px); max-width:760px; margin:0 auto; }
        .final p{ font-size:18px; color:var(--soft); margin-top:20px; }
        .foot{ padding:32px 0; border-top:1px solid var(--line); display:flex; flex-wrap:wrap; gap:16px;
               align-items:center; justify-content:space-between; }
        .foot-links{ display:flex; flex-wrap:wrap; gap:24px; font-size:14px; }
        .foot-links a{ color:var(--soft); } .foot-links a:hover{ color:var(--text); }

        /* AI dev-team task snapshot */
        .roles-row{ display:flex; flex-wrap:wrap; gap:8px; }
        .role-chip{ display:inline-flex; align-items:center; gap:7px; font-size:12.5px; color:var(--soft);
                    border:1px solid var(--line2); border-radius:999px; padding:5px 12px; }
        .tasklist{ margin-top:16px; }
        .task{ display:flex; align-items:center; gap:13px; padding:12px 0; border-top:1px solid var(--line); font-size:14.5px; }
        .task:first-child{ border-top:none; }
        .task.q{ color:var(--dim); }
        .task .st{ width:18px; height:18px; flex:0 0 auto; display:flex; align-items:center; justify-content:center; }
        .task .role{ margin-left:auto; font-family:var(--mono); font-size:11.5px; color:var(--dim);
                     border:1px solid var(--line); border-radius:6px; padding:3px 8px; white-space:nowrap; }
        .dot-b{ width:11px; height:11px; border-radius:999px; background:var(--accent);
                animation:pulseb 1.6s ease-out infinite; }
        @keyframes pulseb{ 0%{ box-shadow:0 0 0 0 rgba(59,118,240,0.55); } 70%{ box-shadow:0 0 0 7px rgba(59,118,240,0); } 100%{ box-shadow:0 0 0 0 rgba(59,118,240,0); } }
        .ring{ width:15px; height:15px; border-radius:999px; border:2px solid var(--dim); box-sizing:border-box; }

        /* founder stories (landing section + /stories) */
        .fcard{ overflow:hidden; display:flex; flex-direction:column; }
        .fcard .shot{ height:180px; background:linear-gradient(135deg,#1b2c54,#0e1a38); }
        .fcard .body{ padding:24px; display:flex; flex-direction:column; gap:10px; flex:1; }
        .fcard .who{ font-family:var(--serif); font-size:22px; font-weight:600; }
        .fcard .role{ font-size:13px; color:var(--dim); }
        .fcard .sum{ font-size:15px; color:var(--soft); line-height:1.6; }
        .fcard .more{ margin-top:auto; padding-top:6px; font-weight:600; font-size:15px; }
        .started{ display:inline-flex; align-items:center; gap:8px; align-self:flex-start; font-size:12.5px; color:var(--soft);
                  border:1px solid var(--line2); border-radius:999px; padding:5px 12px; }
        .started b{ color:var(--text); font-weight:600; }
        .thread{ margin-top:30px; padding:30px 32px; display:grid; grid-template-columns:1fr 2fr; gap:28px; align-items:center;
                 border-color:rgba(59,118,240,0.45); background:linear-gradient(135deg, rgba(59,118,240,0.14), rgba(59,118,240,0.03)); }
        .thread h3{ font-size:clamp(24px,2.6vw,30px); }
        .thread h3 em{ font-style:normal; color:var(--accent2); }
        .thread-pts{ display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }
        .thread-pts div{ font-size:14.5px; color:var(--soft); line-height:1.55; }
        .thread-pts b{ display:block; color:var(--text); font-size:15.5px; margin-bottom:4px; }
        .chapter{ padding:72px 0; border-top:1px solid var(--line); display:grid; grid-template-columns:0.9fr 1.1fr; gap:48px; align-items:start; scroll-margin-top:20px; }
        .chapter .pic{ border:1px solid var(--line2); border-radius:16px; overflow:hidden; box-shadow:0 24px 60px rgba(0,0,0,0.45); }
        .chapter .pic img{ width:100%; display:block; }
        .chapter h2{ font-size:clamp(28px,3.2vw,40px); margin-top:14px; }
        .chapter .by{ margin-top:12px; font-size:15px; color:var(--soft); }
        .chapter .by b{ color:var(--text); }
        .chapter p.lead{ font-size:17px; line-height:1.7; color:var(--soft); margin-top:18px; }
        .chapter .built{ margin-top:22px; display:grid; gap:10px; font-size:15px; color:var(--soft); }
        .chapter .built div{ display:flex; gap:10px; }
        .stats3{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:16px; }

        @media (max-width: 520px){
            .nav-links{ gap:14px; font-size:14px; }
            .nav-cta{ padding:8px 12px; }
            .logo-word{ font-size:20px; }
        }

        @media (max-width: 900px){
            .thread{ grid-template-columns:1fr; padding:26px 22px; }
            .thread-pts{ grid-template-columns:1fr; }
            .chapter{ grid-template-columns:1fr; gap:28px; padding:52px 0; }
        }

        @media (max-width: 900px){
            .hero{ grid-template-columns:1fr; gap:2.5rem; padding:36px 0 64px; }
            .hero-visual{ order:2; }
            .nav-links .hide-sm{ display:none; }
            .band{ padding:52px 0; }
            .final{ padding:72px 0; }
        }
    </style>
