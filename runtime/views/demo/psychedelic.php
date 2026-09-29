<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HELLO BUDDY & DAMON - A Psychedelic Journey Into Madness</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Creepster&family=Bangers&family=Permanent+Marker&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #000;
            cursor: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32"><circle cx="16" cy="16" r="10" fill="%23ff00ff"/><circle cx="16" cy="16" r="5" fill="%2300ffff"/></svg>'), auto;
        }

        .universe {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: linear-gradient(45deg,
                #ff006e, #8338ec, #3a86ff, #06ffa5,
                #ffbe0b, #fb5607, #ff006e);
            background-size: 400% 400%;
            animation: cosmicShift 3s ease infinite, pulseBackground 0.5s ease infinite;
        }

        @keyframes cosmicShift {
            0%, 100% { background-position: 0% 50%; }
            25% { background-position: 100% 0%; }
            50% { background-position: 100% 100%; }
            75% { background-position: 0% 100%; }
        }

        @keyframes pulseBackground {
            0%, 100% { filter: hue-rotate(0deg) saturate(150%); }
            50% { filter: hue-rotate(30deg) saturate(200%); }
        }

        .fractal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: repeating-conic-gradient(
                from 0deg at 50% 50%,
                transparent 0deg,
                rgba(255,0,255,0.1) 10deg,
                transparent 20deg
            );
            animation: fractalSpin 8s linear infinite;
            pointer-events: none;
        }

        @keyframes fractalSpin {
            from { transform: rotate(0deg) scale(1); }
            to { transform: rotate(360deg) scale(1); }
        }

        .kaleidoscope {
            position: fixed;
            top: 50%;
            left: 50%;
            width: 200vmax;
            height: 200vmax;
            transform: translate(-50%, -50%);
            background:
                repeating-linear-gradient(
                    0deg,
                    transparent,
                    transparent 2px,
                    rgba(0,255,255,0.03) 2px,
                    rgba(0,255,255,0.03) 4px
                ),
                repeating-linear-gradient(
                    90deg,
                    transparent,
                    transparent 2px,
                    rgba(255,0,255,0.03) 2px,
                    rgba(255,0,255,0.03) 4px
                );
            animation: kaleidoRotate 20s linear infinite;
            pointer-events: none;
        }

        @keyframes kaleidoRotate {
            from { transform: translate(-50%, -50%) rotate(0deg); }
            to { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .eye-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            perspective: 1000px;
        }

        .giant-eye {
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%,
                #fff 0%,
                #ffecd2 40%,
                #fcb69f 60%,
                #d4a5a5 80%);
            box-shadow:
                inset -20px -20px 60px rgba(0,0,0,0.3),
                0 0 100px rgba(255,255,255,0.5),
                0 0 200px rgba(255,0,255,0.5);
            animation: eyeFloat 4s ease-in-out infinite, eyeScale 2s ease-in-out infinite;
        }

        .iris {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 150px;
            height: 150px;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            background: radial-gradient(circle at 50% 50%,
                #000 0%,
                #1a0a2e 20%,
                #16213e 40%,
                #0f3460 60%,
                #e94560 80%);
            animation: irisColor 1s ease infinite, irisPulse 0.3s ease infinite;
        }

        .pupil {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            background: radial-gradient(circle at 30% 30%,
                #333 0%,
                #000 50%);
            animation: pupilTrack 3s ease-in-out infinite;
        }

        .pupil::after {
            content: '';
            position: absolute;
            top: 20%;
            left: 20%;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: rgba(255,255,255,0.8);
        }

        @keyframes eyeFloat {
            0%, 100% { transform: translateY(0) rotateX(0deg) rotateY(0deg); }
            25% { transform: translateY(-30px) rotateX(10deg) rotateY(10deg); }
            50% { transform: translateY(0) rotateX(-10deg) rotateY(-10deg); }
            75% { transform: translateY(30px) rotateX(5deg) rotateY(-5deg); }
        }

        @keyframes eyeScale {
            0%, 100% { scale: 1; }
            50% { scale: 1.1; }
        }

        @keyframes irisColor {
            0%, 100% { filter: hue-rotate(0deg); }
            50% { filter: hue-rotate(180deg); }
        }

        @keyframes irisPulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); }
            50% { transform: translate(-50%, -50%) scale(1.05); }
        }

        @keyframes pupilTrack {
            0%, 100% { transform: translate(-50%, -50%); }
            25% { transform: translate(-30%, -30%); }
            50% { transform: translate(-70%, -50%); }
            75% { transform: translate(-40%, -70%); }
        }

        .title-container {
            position: fixed;
            top: 5%;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
            text-align: center;
        }

        .hello-text {
            font-family: 'Bangers', cursive;
            font-size: 8vw;
            color: transparent;
            background: linear-gradient(90deg,
                #ff0000, #ff7700, #ffff00, #00ff00,
                #0077ff, #7700ff, #ff00ff, #ff0000);
            background-size: 200% 100%;
            -webkit-background-clip: text;
            background-clip: text;
            animation: rainbowText 0.5s linear infinite,
                       textWobble 0.2s ease infinite,
                       textGrow 2s ease-in-out infinite;
            text-shadow:
                3px 3px 0 #ff00ff,
                -3px -3px 0 #00ffff,
                6px 6px 0 #ffff00,
                -6px -6px 0 #ff00ff;
            letter-spacing: 0.5em;
        }

        @keyframes rainbowText {
            from { background-position: 0% 50%; }
            to { background-position: 200% 50%; }
        }

        @keyframes textWobble {
            0%, 100% { transform: skewX(0deg) scaleY(1); }
            25% { transform: skewX(5deg) scaleY(1.1); }
            50% { transform: skewX(0deg) scaleY(0.9); }
            75% { transform: skewX(-5deg) scaleY(1.05); }
        }

        @keyframes textGrow {
            0%, 100% { letter-spacing: 0.5em; }
            50% { letter-spacing: 0.8em; }
        }

        .names-container {
            position: fixed;
            bottom: 10%;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-around;
            z-index: 100;
        }

        .name {
            font-family: 'Creepster', cursive;
            font-size: 10vw;
            position: relative;
        }

        .buddy {
            color: #00ff88;
            text-shadow:
                0 0 10px #00ff88,
                0 0 20px #00ff88,
                0 0 40px #00ff88,
                0 0 80px #00ffff,
                5px 5px 0 #ff00ff,
                -5px -5px 0 #ffff00;
            animation: buddyFloat 2s ease-in-out infinite,
                       glitchBuddy 0.3s infinite,
                       buddyRotate 8s linear infinite;
        }

        .damon {
            color: #ff0088;
            text-shadow:
                0 0 10px #ff0088,
                0 0 20px #ff0088,
                0 0 40px #ff0088,
                0 0 80px #ff00ff,
                5px 5px 0 #00ffff,
                -5px -5px 0 #ffff00;
            animation: damonFloat 2.5s ease-in-out infinite,
                       glitchDamon 0.25s infinite,
                       damonRotate 10s linear infinite reverse;
        }

        @keyframes buddyFloat {
            0%, 100% { transform: translateY(0) rotate(-5deg); }
            50% { transform: translateY(-50px) rotate(5deg); }
        }

        @keyframes damonFloat {
            0%, 100% { transform: translateY(-30px) rotate(5deg); }
            50% { transform: translateY(20px) rotate(-5deg); }
        }

        @keyframes glitchBuddy {
            0% { transform: translate(0); }
            20% { transform: translate(-5px, 5px); }
            40% { transform: translate(-5px, -5px); }
            60% { transform: translate(5px, 5px); }
            80% { transform: translate(5px, -5px); }
            100% { transform: translate(0); }
        }

        @keyframes glitchDamon {
            0% { transform: translate(0); }
            10% { transform: translate(3px, -3px); clip-path: polygon(0 0, 100% 0, 100% 45%, 0 45%); }
            20% { transform: translate(-3px, 3px); clip-path: polygon(0 45%, 100% 45%, 100% 100%, 0 100%); }
            30% { transform: translate(0); clip-path: none; }
        }

        @keyframes buddyRotate {
            from { filter: hue-rotate(0deg); }
            to { filter: hue-rotate(360deg); }
        }

        @keyframes damonRotate {
            from { filter: hue-rotate(0deg) drop-shadow(0 0 30px currentColor); }
            to { filter: hue-rotate(360deg) drop-shadow(0 0 30px currentColor); }
        }

        .floating-shape {
            position: fixed;
            pointer-events: none;
            animation: floatAround 10s ease-in-out infinite;
        }

        .triangle {
            width: 0;
            height: 0;
            border-left: 50px solid transparent;
            border-right: 50px solid transparent;
            border-bottom: 86px solid;
            animation: triangleSpin 4s linear infinite, colorCycle 1s infinite;
        }

        .circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 10px solid;
            animation: circlePulse 2s ease infinite, colorCycle 1.5s infinite;
        }

        .square {
            width: 60px;
            height: 60px;
            animation: squareRotate 3s linear infinite, colorCycle 0.8s infinite;
        }

        @keyframes floatAround {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(100px, -100px) rotate(90deg); }
            50% { transform: translate(-50px, 100px) rotate(180deg); }
            75% { transform: translate(-100px, -50px) rotate(270deg); }
        }

        @keyframes triangleSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes circlePulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.5); }
        }

        @keyframes squareRotate {
            from { transform: rotate(0deg) skew(0deg); }
            50% { transform: rotate(180deg) skew(20deg); }
            to { transform: rotate(360deg) skew(0deg); }
        }

        @keyframes colorCycle {
            0% { border-color: #ff0000; background-color: rgba(255,0,0,0.3); }
            16% { border-color: #ff7700; background-color: rgba(255,119,0,0.3); }
            33% { border-color: #ffff00; background-color: rgba(255,255,0,0.3); }
            50% { border-color: #00ff00; background-color: rgba(0,255,0,0.3); }
            66% { border-color: #0077ff; background-color: rgba(0,119,255,0.3); }
            83% { border-color: #ff00ff; background-color: rgba(255,0,255,0.3); }
            100% { border-color: #ff0000; background-color: rgba(255,0,0,0.3); }
        }

        .worm {
            position: fixed;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            animation: wormWiggle 2s ease-in-out infinite;
        }

        @keyframes wormWiggle {
            0%, 100% { transform: translateX(0) scaleX(1); }
            25% { transform: translateX(30px) scaleX(1.3); }
            50% { transform: translateX(0) scaleX(0.7); }
            75% { transform: translateX(-30px) scaleX(1.3); }
        }

        .message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-family: 'Permanent Marker', cursive;
            font-size: 3vw;
            color: #fff;
            text-align: center;
            z-index: 50;
            animation: messagePhase 4s ease-in-out infinite;
            text-shadow: 0 0 50px #fff;
        }

        @keyframes messagePhase {
            0%, 100% { opacity: 0; transform: translate(-50%, -50%) scale(0.5) rotate(-10deg); }
            50% { opacity: 1; transform: translate(-50%, -50%) scale(1) rotate(10deg); }
        }

        .spiral {
            position: fixed;
            top: 50%;
            left: 50%;
            width: 600px;
            height: 600px;
            transform: translate(-50%, -50%);
            background: conic-gradient(
                from 0deg,
                #ff0000, #ff7700, #ffff00, #00ff00,
                #00ffff, #0077ff, #7700ff, #ff00ff, #ff0000
            );
            border-radius: 50%;
            mask-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill='%23fff' d='M100,10 Q140,30 160,60 Q180,90 160,120 Q140,150 100,160 Q60,170 40,140 Q20,110 40,80 Q60,50 100,50 Q130,50 140,70 Q150,90 130,100 Q110,110 100,100'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill='%23fff' d='M100,10 Q140,30 160,60 Q180,90 160,120 Q140,150 100,160 Q60,170 40,140 Q20,110 40,80 Q60,50 100,50 Q130,50 140,70 Q150,90 130,100 Q110,110 100,100'/%3E%3C/svg%3E");
            animation: spiralSpin 5s linear infinite;
            opacity: 0.5;
            z-index: 10;
        }

        @keyframes spiralSpin {
            from { transform: translate(-50%, -50%) rotate(0deg); }
            to { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .particle {
            position: fixed;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            pointer-events: none;
            animation: particleFly 3s ease-out infinite;
        }

        @keyframes particleFly {
            0% {
                opacity: 1;
                transform: translate(0, 0) scale(1);
            }
            100% {
                opacity: 0;
                transform: translate(var(--dx), var(--dy)) scale(0);
            }
        }

        .strobe {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #fff;
            animation: strobe 0.15s steps(1) infinite;
            pointer-events: none;
            z-index: 200;
            mix-blend-mode: difference;
        }

        @keyframes strobe {
            0%, 49% { opacity: 0; }
            50%, 100% { opacity: 0.1; }
        }

        .scanlines {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: repeating-linear-gradient(
                0deg,
                transparent,
                transparent 2px,
                rgba(0,0,0,0.1) 2px,
                rgba(0,0,0,0.1) 4px
            );
            pointer-events: none;
            z-index: 150;
            animation: scanlineMove 0.1s linear infinite;
        }

        @keyframes scanlineMove {
            from { transform: translateY(0); }
            to { transform: translateY(4px); }
        }

        .vhs-effect {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 160;
            animation: vhsGlitch 0.5s infinite;
        }

        @keyframes vhsGlitch {
            0% {
                clip-path: inset(10% 0 80% 0);
                transform: translate(-5px, 0);
            }
            10% {
                clip-path: inset(60% 0 20% 0);
                transform: translate(5px, 0);
            }
            20% {
                clip-path: inset(30% 0 50% 0);
                transform: translate(-3px, 0);
            }
            30% {
                clip-path: none;
                transform: translate(0, 0);
            }
            100% {
                clip-path: none;
                transform: translate(0, 0);
            }
        }

        .back-link {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 300;
            font-family: 'Bangers', cursive;
            font-size: 1.5rem;
            color: #fff;
            text-decoration: none;
            padding: 15px 30px;
            background: linear-gradient(90deg, #ff00ff, #00ffff, #ff00ff);
            background-size: 200% 100%;
            animation: rainbowText 1s linear infinite;
            border-radius: 50px;
            text-shadow: 2px 2px 0 #000;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .back-link:hover {
            transform: translateX(-50%) scale(1.2) rotate(5deg);
            box-shadow: 0 0 50px #ff00ff, 0 0 100px #00ffff;
        }

        .infinity-symbol {
            position: fixed;
            font-size: 200px;
            opacity: 0.3;
            animation: infinitySpin 10s linear infinite;
        }

        @keyframes infinitySpin {
            from { transform: rotate(0deg); filter: hue-rotate(0deg); }
            to { transform: rotate(360deg); filter: hue-rotate(360deg); }
        }

        .matrix-rain {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 5;
            overflow: hidden;
        }

        .matrix-column {
            position: absolute;
            top: -100%;
            font-family: monospace;
            font-size: 20px;
            color: #0f0;
            text-shadow: 0 0 10px #0f0;
            animation: matrixFall linear infinite;
            writing-mode: vertical-rl;
            opacity: 0.5;
        }

        @keyframes matrixFall {
            from { transform: translateY(-100%); }
            to { transform: translateY(200vh); }
        }
    </style>
</head>
<body>
    <div class="universe"></div>
    <div class="fractal-overlay"></div>
    <div class="kaleidoscope"></div>
    <div class="strobe"></div>
    <div class="scanlines"></div>

    <div class="matrix-rain" id="matrixRain"></div>

    <div class="spiral"></div>

    <div class="eye-container">
        <div class="giant-eye">
            <div class="iris">
                <div class="pupil"></div>
            </div>
        </div>
    </div>

    <div class="title-container">
        <h1 class="hello-text">HELLO WORLD</h1>
    </div>

    <div class="names-container">
        <span class="name buddy">BUDDY</span>
        <span class="name damon">DAMON</span>
    </div>

    <div class="message">
        Welcome to the void, friends.<br>
        Reality is but a suggestion.
    </div>

    <!-- Floating shapes -->
    <div class="floating-shape triangle" style="top: 20%; left: 10%; animation-delay: 0s;"></div>
    <div class="floating-shape circle" style="top: 30%; right: 15%; animation-delay: 1s;"></div>
    <div class="floating-shape square" style="bottom: 25%; left: 20%; animation-delay: 2s;"></div>
    <div class="floating-shape triangle" style="top: 60%; right: 10%; animation-delay: 0.5s;"></div>
    <div class="floating-shape circle" style="bottom: 40%; right: 25%; animation-delay: 1.5s;"></div>
    <div class="floating-shape square" style="top: 15%; right: 30%; animation-delay: 2.5s;"></div>

    <!-- Infinity symbols -->
    <div class="infinity-symbol" style="top: 20%; left: 5%;">&#8734;</div>
    <div class="infinity-symbol" style="bottom: 20%; right: 5%; animation-direction: reverse;">&#8734;</div>

    <!-- Worms -->
    <div class="worm" style="top: 40%; left: 5%; background: #ff00ff; animation-delay: 0s;"></div>
    <div class="worm" style="top: 50%; left: 7%; background: #00ffff; animation-delay: 0.1s;"></div>
    <div class="worm" style="top: 60%; left: 5%; background: #ffff00; animation-delay: 0.2s;"></div>
    <div class="worm" style="top: 40%; right: 5%; background: #00ff00; animation-delay: 0.15s;"></div>
    <div class="worm" style="top: 50%; right: 7%; background: #ff0000; animation-delay: 0.25s;"></div>
    <div class="worm" style="top: 60%; right: 5%; background: #ff00ff; animation-delay: 0.05s;"></div>

    <a href="/" class="back-link">ESCAPE THE VOID</a>

    <script>
        // Create matrix rain
        const matrixRain = document.getElementById('matrixRain');
        const chars = 'BUDDYDAMONHELLOWORLD01アイウエオカキクケコ&#9734;&#9733;&#10084;&#9829;';

        for (let i = 0; i < 30; i++) {
            const column = document.createElement('div');
            column.className = 'matrix-column';
            column.style.left = (i * 3.5) + '%';
            column.style.animationDuration = (3 + Math.random() * 5) + 's';
            column.style.animationDelay = Math.random() * 5 + 's';

            let text = '';
            for (let j = 0; j < 30; j++) {
                text += chars[Math.floor(Math.random() * chars.length)] + ' ';
            }
            column.textContent = text;
            matrixRain.appendChild(column);
        }

        // Create particles on mouse move
        document.addEventListener('mousemove', (e) => {
            if (Math.random() > 0.7) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = e.clientX + 'px';
                particle.style.top = e.clientY + 'px';
                particle.style.background = `hsl(${Math.random() * 360}, 100%, 50%)`;
                particle.style.setProperty('--dx', (Math.random() - 0.5) * 200 + 'px');
                particle.style.setProperty('--dy', (Math.random() - 0.5) * 200 + 'px');
                document.body.appendChild(particle);
                setTimeout(() => particle.remove(), 3000);
            }
        });

        // Eye tracking
        const eye = document.querySelector('.giant-eye');
        const pupil = document.querySelector('.pupil');

        document.addEventListener('mousemove', (e) => {
            const rect = eye.getBoundingClientRect();
            const eyeCenterX = rect.left + rect.width / 2;
            const eyeCenterY = rect.top + rect.height / 2;

            const angle = Math.atan2(e.clientY - eyeCenterY, e.clientX - eyeCenterX);
            const distance = Math.min(30, Math.hypot(e.clientX - eyeCenterX, e.clientY - eyeCenterY) / 10);

            const x = Math.cos(angle) * distance;
            const y = Math.sin(angle) * distance;

            pupil.style.transform = `translate(calc(-50% + ${x}px), calc(-50% + ${y}px))`;
        });

        // Random glitch effect
        setInterval(() => {
            if (Math.random() > 0.9) {
                document.body.style.filter = `hue-rotate(${Math.random() * 360}deg) invert(${Math.random() > 0.5 ? 1 : 0})`;
                setTimeout(() => {
                    document.body.style.filter = '';
                }, 100);
            }
        }, 200);

        // Audio context for weird sounds (on click)
        document.addEventListener('click', () => {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();

                osc.connect(gain);
                gain.connect(ctx.destination);

                osc.type = ['sine', 'square', 'sawtooth', 'triangle'][Math.floor(Math.random() * 4)];
                osc.frequency.setValueAtTime(Math.random() * 1000 + 100, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(Math.random() * 500 + 50, ctx.currentTime + 0.5);

                gain.gain.setValueAtTime(0.3, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);

                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.5);
            } catch (e) {
                // Audio not supported or blocked
            }
        });

        // Typing effect for message
        const messages = [
            "Reality is but a suggestion.",
            "BUDDY and DAMON rule this dimension!",
            "Colors are screaming.",
            "Do you see the patterns?",
            "The eye sees all.",
            "Welcome to the madness.",
            "Time is a flat circle.",
            "HELLO FROM THE OTHER SIDE!",
            "We are all just vibrations.",
            "The void welcomes you."
        ];

        const messageEl = document.querySelector('.message');
        let msgIndex = 0;

        setInterval(() => {
            msgIndex = (msgIndex + 1) % messages.length;
            messageEl.innerHTML = messages[msgIndex];
        }, 4000);

        console.log('%c BUDDY & DAMON ', 'background: linear-gradient(90deg, #ff00ff, #00ffff); color: #fff; font-size: 50px; font-weight: bold; text-shadow: 3px 3px 0 #000;');
        console.log('%c Welcome to the psychedelic void! ', 'background: #000; color: #0f0; font-size: 20px;');
    </script>
</body>
</html>
