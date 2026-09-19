<?php
// ── Birthday page ─────────────────────────────────────────────
// Self-contained: PHP data logic, CSS, and the confetti effect all
// live in this one file. Lives beside home.php in TWM root — needs
// only auth_check.php / test_sqlsrv.php / nav.php from the same
// setup home.php already uses.

date_default_timezone_set('Asia/Manila');
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/test_sqlsrv.php';

auth_check();

$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';

// ── Data: today's birthdays + next 14 days (a 15-day window) ────
// ViewBirthday columns used: BMonth, Bday, Birth_date, FirstName,
// LastName, Department, Position_held, Picture.
$todayBirthdays    = [];
$upcomingBirthdays = [];

$bMonth = (int) date('m');
$bDay   = (int) date('d');
$todayStmt = sqlsrv_query($conn, "SELECT * FROM ViewBirthday WHERE BMonth = ? AND Bday = ?", [$bMonth, $bDay]);
if ($todayStmt) {
    while ($row = sqlsrv_fetch_array($todayStmt, SQLSRV_FETCH_ASSOC)) { $todayBirthdays[] = $row; }
    sqlsrv_free_stmt($todayStmt);
}

// Explicit (month, day) pairs rather than a Bday BETWEEN range — a range
// breaks the moment the window crosses a month boundary.
$offsetByKey = [];
$pairs  = [];
$params = [];
for ($i = 1; $i <= 14; $i++) {
    $t = strtotime("+$i day");
    $m = (int) date('m', $t);
    $d = (int) date('d', $t);
    $offsetByKey["$m-$d"] = $i;
    $pairs[]  = "(BMonth = ? AND Bday = ?)";
    $params[] = $m; $params[] = $d;
}
$upcomingStmt = sqlsrv_query($conn, "SELECT * FROM ViewBirthday WHERE " . implode(' OR ', $pairs), $params);
if ($upcomingStmt) {
    while ($row = sqlsrv_fetch_array($upcomingStmt, SQLSRV_FETCH_ASSOC)) { $upcomingBirthdays[] = $row; }
    sqlsrv_free_stmt($upcomingStmt);
}
usort($upcomingBirthdays, fn($a, $b) =>
    ($offsetByKey["{$a['BMonth']}-{$a['Bday']}"] ?? 99) <=> ($offsetByKey["{$b['BMonth']}-{$b['Bday']}"] ?? 99)
);

$celebrateToday = !empty($todayBirthdays);

// Same resolution employee-list.php uses: TWM stores forward-slash paths
// under employee_pics/, the legacy portal stores backslash paths directly
// under uploads\ — try TWM first, fall back to the legacy portal, then to
// a colored-initials avatar if there's no picture at all.
function bdayResolvePic($rawPic): array {
    $rawPic  = trim((string) $rawPic);
    $normPic = str_replace('\\', '/', $rawPic);
    $picFile = $normPic !== '' ? basename($normPic) : '';
    if ($picFile === '') return ['', ''];
    $twmPic = strpos($normPic, 'employee_pics') !== false
        ? (str_starts_with($normPic, '/') ? $normPic : '/TWM/' . $normPic)
        : '/TWM/uploads/employee_pics/' . $picFile;
    $legacyPic = '/tradewellportal/uploads/' . $picFile;
    return [$twmPic, $legacyPic];
}
function bdayInitials(string $first, string $last): string { return strtoupper(substr($first,0,1).substr($last,0,1)); }
function bdayAvatarColor(string $name): string { $c=['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316']; return $c[abs(crc32($name))%count($c)]; }

// Renders one avatar <img> (with TWM → legacy → initials fallback chain).
// $size controls the initials-fallback font size.
function bdayAvatarMarkup(array $b, string $size = '1rem'): string {
    $name = trim(($b['FirstName'] ?? '') . ' ' . ($b['LastName'] ?? ''));
    [$twmPic, $legacyPic] = bdayResolvePic($b['Picture'] ?? '');
    $initialsHtml = '<span class="bday-avatar-fallback" style="background:' . bdayAvatarColor($name) . ';font-size:' . $size . ';">'
        . htmlspecialchars(bdayInitials($b['FirstName'] ?? '', $b['LastName'] ?? '')) . '</span>';
    if ($twmPic === '') {
        return $initialsHtml;
    }
    $fallbackJs = "this.onerror=null;this.parentElement.innerHTML='" . addslashes($initialsHtml) . "';";
    return '<img src="' . htmlspecialchars($twmPic) . '" alt=""'
        . ' onerror="this.onerror=function(){' . htmlspecialchars($fallbackJs, ENT_QUOTES) . '};this.src=\'' . htmlspecialchars($legacyPic, ENT_QUOTES) . '\';">';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Birthdays · Tradewell Admin</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <style>
    :root {
      --blue-deep: #08173d; --blue-bright: #4380e2; --blue-light: #93c5fd;
      --white: #ffffff;
      --w10: rgba(255,255,255,0.10); --w15: rgba(255,255,255,0.15);
      --w25: rgba(255,255,255,0.25); --w60: rgba(255,255,255,0.60);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      min-height: 100%; font-family: 'DM Sans', sans-serif;
      background: linear-gradient(145deg, var(--blue-bright) 0%, var(--blue-deep) 100%);
      background-attachment: fixed;
    }
    .page { display: flex; flex-direction: column; align-items: center; min-height: 100vh; padding: 2rem 2rem 3rem; gap: 1.75rem; }

    .bday-page-header { width: 100%; max-width: 1480px; display: flex; align-items: center; gap: .9rem; padding-bottom: 1.1rem; border-bottom: 1px solid var(--w15); animation: fadeUp .4s ease both; }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(13px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .bday-back {
      display: flex; align-items: center; justify-content: center;
      width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
      background: var(--w10); border: 1px solid var(--w25); color: var(--white); text-decoration: none;
    }
    .bday-back:hover { background: var(--w15); }
    .bday-page-title { font-family: 'Sora', sans-serif; font-size: 1.25rem; font-weight: 800; color: var(--white); }
    .bday-page-subtitle { font-size: .78rem; color: var(--w60); }
    .bday-empty { width: 100%; max-width: 1480px; text-align: center; color: var(--w60); font-size: .85rem; padding: 2rem 0; }

    /* ── Birthday widget (today + upcoming) ── */
    .hub-birthday { width: 100%; max-width: 1480px; display: flex; flex-direction: column; gap: .9rem; animation: fadeUp .4s .05s ease both; }
    .bday-today-row { display: flex; flex-wrap: wrap; gap: .75rem; }
    .bday-hero {
      display: flex; align-items: center; gap: .9rem;
      background: linear-gradient(135deg, rgba(251,191,36,.14), rgba(255,255,255,.07));
      border: 1px solid rgba(251,191,36,.35);
      border-radius: 16px; padding: .9rem 1.3rem;
      flex: 1 1 320px; min-width: 280px;
    }
    .bday-hero-avatar {
      width: 52px; height: 52px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
      background: rgba(255,255,255,.1); border: 2px solid rgba(251,191,36,.5);
      display: flex; align-items: center; justify-content: center;
    }
    .bday-hero-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .bday-hero-eyebrow {
      display: flex; align-items: center; gap: .3rem;
      font-size: .68rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
      color: #fbbf24; margin-bottom: .15rem;
    }
    .bday-hero-name { font-family: 'Sora', sans-serif; font-size: 1.05rem; font-weight: 800; color: var(--white); }
    .bday-hero-meta { font-size: .76rem; color: var(--w60); margin-top: .1rem; }

    .bday-upcoming-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: .5rem; }
    .qa-label { display: flex; align-items: center; gap: .35rem; font-size: .76rem; font-weight: 700; color: var(--w60); text-transform: uppercase; letter-spacing: .04em; margin-bottom: .5rem; }
    .bday-chip {
      display: flex; align-items: center; gap: .5rem; width: 100%;
      background: rgba(255,255,255,0.07); border: 1px solid var(--w15);
      border-radius: 999px; padding: .35rem .9rem .35rem .35rem;
    }
    .bday-chip-avatar {
      width: 28px; height: 28px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
      background: rgba(255,255,255,.1); display: flex; align-items: center; justify-content: center;
    }
    .bday-chip-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .bday-chip-body { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
    .bday-chip-name { font-size: .78rem; font-weight: 600; color: var(--white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bday-chip-date { font-size: .68rem; color: var(--w60); }

    .bday-avatar-fallback {
      width: 100%; height: 100%; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-weight: 700;
    }

    /* Staggered entrance — each card/chip gets its own animation-delay inline */
    .bday-pop { opacity: 0; animation: bdayPop .45s ease both; }
    @keyframes bdayPop {
      0%   { opacity: 0; transform: translateY(10px) scale(.96); }
      100% { opacity: 1; transform: translateY(0) scale(1); }
    }
  </style>
</head>
<body>
<div class="page">

  <div class="bday-page-header">
    <a href="home.php" class="bday-back" title="Back to home"><i class="bi bi-arrow-left"></i></a>
    <div>
      <div class="bday-page-title"><i class="bi bi-gift-fill" style="color:#fbbf24;"></i> Birthdays</div>
      <div class="bday-page-subtitle">Today and the next 15 days</div>
    </div>
  </div>

  <?php if (empty($todayBirthdays) && empty($upcomingBirthdays)): ?>
  <div class="bday-empty">No birthdays in the next 15 days.</div>
  <?php else: ?>
  <div class="hub-birthday">
    <?php if (!empty($todayBirthdays)): ?>
    <div class="bday-today-row">
      <?php foreach ($todayBirthdays as $i => $b): ?>
      <div class="bday-hero bday-pop" style="animation-delay: <?= $i * 0.08 ?>s">
        <div class="bday-hero-avatar"><?= bdayAvatarMarkup($b, '1rem') ?></div>
        <div>
          <div class="bday-hero-eyebrow"><i class="bi bi-gift-fill"></i> Happy Birthday!</div>
          <div class="bday-hero-name"><?= htmlspecialchars(trim(($b['FirstName'] ?? '') . ' ' . ($b['LastName'] ?? ''))) ?></div>
          <div class="bday-hero-meta">
            <?= htmlspecialchars($b['Position_held'] ?? '') ?><?php if (!empty($b['Department'])): ?> · <?= htmlspecialchars($b['Department']) ?><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($upcomingBirthdays)): ?>
    <div>
      <div class="qa-label"><i class="bi bi-calendar-heart"></i> Upcoming birthdays</div>
      <div class="bday-upcoming-row">
        <?php foreach ($upcomingBirthdays as $i => $b): ?>
        <div class="bday-chip bday-pop" style="animation-delay: <?= 0.15 + $i * 0.04 ?>s">
          <span class="bday-chip-avatar"><?= bdayAvatarMarkup($b, '.6rem') ?></span>
          <span class="bday-chip-body">
            <span class="bday-chip-name"><?= htmlspecialchars(trim(($b['FirstName'] ?? '') . ' ' . ($b['LastName'] ?? ''))) ?></span>
            <span class="bday-chip-date"><?= (isset($b['Birth_date']) && $b['Birth_date'] instanceof DateTime) ? $b['Birth_date']->format('M j') : '' ?></span>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
<?php if ($celebrateToday): ?>
<script>
var confetti = {
	maxCount: 150,		//set max confetti count
	speed: 2,			//set the particle animation speed
	frameInterval: 15,	//the confetti animation frame interval in milliseconds
	alpha: 1.0,			//the alpha opacity of the confetti (between 0 and 1, where 1 is opaque and 0 is invisible)
	gradient: false,	//whether to use gradients for the confetti particles
	start: null,		//call to start confetti animation (with optional timeout in milliseconds, and optional min and max random confetti count)
	stop: null,			//call to stop adding confetti
	toggle: null,		//call to start or stop the confetti animation depending on whether it's already running
	pause: null,		//call to freeze confetti animation
	resume: null,		//call to unfreeze confetti animation
	togglePause: null,	//call to toggle whether the confetti animation is paused
	remove: null,		//call to stop the confetti animation and remove all confetti immediately
	isPaused: null,		//call and returns true or false depending on whether the confetti animation is paused
	isRunning: null		//call and returns true or false depending on whether the animation is running
};

(function() {
	confetti.start = startConfetti;
	confetti.stop = stopConfetti;
	confetti.toggle = toggleConfetti;
	confetti.pause = pauseConfetti;
	confetti.resume = resumeConfetti;
	confetti.togglePause = toggleConfettiPause;
	confetti.isPaused = isConfettiPaused;
	confetti.remove = removeConfetti;
	confetti.isRunning = isConfettiRunning;
	var supportsAnimationFrame = window.requestAnimationFrame || window.webkitRequestAnimationFrame || window.mozRequestAnimationFrame || window.oRequestAnimationFrame || window.msRequestAnimationFrame;
	var colors = ["rgba(30,144,255,", "rgba(107,142,35,", "rgba(255,215,0,", "rgba(255,192,203,", "rgba(106,90,205,", "rgba(173,216,230,", "rgba(238,130,238,", "rgba(152,251,152,", "rgba(70,130,180,", "rgba(244,164,96,", "rgba(210,105,30,", "rgba(220,20,60,"];
	var streamingConfetti = false;
	var animationTimer = null;
	var pause = false;
	var lastFrameTime = Date.now();
	var particles = [];
	var waveAngle = 0;
	var context = null;

	function resetParticle(particle, width, height) {
		particle.color = colors[(Math.random() * colors.length) | 0] + (confetti.alpha + ")");
		particle.color2 = colors[(Math.random() * colors.length) | 0] + (confetti.alpha + ")");
		particle.x = Math.random() * width;
		particle.y = Math.random() * height - height;
		particle.diameter = Math.random() * 10 + 5;
		particle.tilt = Math.random() * 10 - 10;
		particle.tiltAngleIncrement = Math.random() * 0.07 + 0.05;
		particle.tiltAngle = Math.random() * Math.PI;
		return particle;
	}

	function toggleConfettiPause() {
		if (pause)
			resumeConfetti();
		else
			pauseConfetti();
	}

	function isConfettiPaused() {
		return pause;
	}

	function pauseConfetti() {
		pause = true;
	}

	function resumeConfetti() {
		pause = false;
		runAnimation();
	}

	function runAnimation() {
		if (pause)
			return;
		else if (particles.length === 0) {
			context.clearRect(0, 0, window.innerWidth, window.innerHeight);
			animationTimer = null;
		} else {
			var now = Date.now();
			var delta = now - lastFrameTime;
			if (!supportsAnimationFrame || delta > confetti.frameInterval) {
				context.clearRect(0, 0, window.innerWidth, window.innerHeight);
				updateParticles();
				drawParticles(context);
				lastFrameTime = now - (delta % confetti.frameInterval);
			}
			animationTimer = requestAnimationFrame(runAnimation);
		}
	}

	function startConfetti(timeout, min, max) {
		var width = window.innerWidth;
		var height = window.innerHeight;
		window.requestAnimationFrame = (function() {
			return window.requestAnimationFrame ||
				window.webkitRequestAnimationFrame ||
				window.mozRequestAnimationFrame ||
				window.oRequestAnimationFrame ||
				window.msRequestAnimationFrame ||
				function (callback) {
					return window.setTimeout(callback, confetti.frameInterval);
				};
		})();
		var canvas = document.getElementById("confetti-canvas");
		if (canvas === null) {
			canvas = document.createElement("canvas");
			canvas.setAttribute("id", "confetti-canvas");
			canvas.setAttribute("style", "display:block;z-index:999999;pointer-events:none;position:fixed;top:0");
			document.body.prepend(canvas);
			canvas.width = width;
			canvas.height = height;
			window.addEventListener("resize", function() {
				canvas.width = window.innerWidth;
				canvas.height = window.innerHeight;
			}, true);
			context = canvas.getContext("2d");
		} else if (context === null)
			context = canvas.getContext("2d");
		var count = confetti.maxCount;
		if (min) {
			if (max) {
				if (min == max)
					count = particles.length + max;
				else {
					if (min > max) {
						var temp = min;
						min = max;
						max = temp;
					}
					count = particles.length + ((Math.random() * (max - min) + min) | 0);
				}
			} else
				count = particles.length + min;
		} else if (max)
			count = particles.length + max;
		while (particles.length < count)
			particles.push(resetParticle({}, width, height));
		streamingConfetti = true;
		pause = false;
		runAnimation();
		if (timeout) {
			window.setTimeout(stopConfetti, timeout);
		}
	}

	function stopConfetti() {
		streamingConfetti = false;
	}

	function removeConfetti() {
		stop();
		pause = false;
		particles = [];
	}

	function toggleConfetti() {
		if (streamingConfetti)
			stopConfetti();
		else
			startConfetti();
	}
	
	function isConfettiRunning() {
		return streamingConfetti;
	}

	function drawParticles(context) {
		var particle;
		var x, y, x2, y2;
		for (var i = 0; i < particles.length; i++) {
			particle = particles[i];
			context.beginPath();
			context.lineWidth = particle.diameter;
			x2 = particle.x + particle.tilt;
			x = x2 + particle.diameter / 2;
			y2 = particle.y + particle.tilt + particle.diameter / 2;
			if (confetti.gradient) {
				var gradient = context.createLinearGradient(x, particle.y, x2, y2);
				gradient.addColorStop("0", particle.color);
				gradient.addColorStop("1.0", particle.color2);
				context.strokeStyle = gradient;
			} else
				context.strokeStyle = particle.color;
			context.moveTo(x, particle.y);
			context.lineTo(x2, y2);
			context.stroke();
		}
	}

	function updateParticles() {
		var width = window.innerWidth;
		var height = window.innerHeight;
		var particle;
		waveAngle += 0.01;
		for (var i = 0; i < particles.length; i++) {
			particle = particles[i];
			if (!streamingConfetti && particle.y < -15)
				particle.y = height + 100;
			else {
				particle.tiltAngle += particle.tiltAngleIncrement;
				particle.x += Math.sin(waveAngle) - 0.5;
				particle.y += (Math.cos(waveAngle) + particle.diameter + confetti.speed) * 0.5;
				particle.tilt = Math.sin(particle.tiltAngle) * 15;
			}
			if (particle.x > width + 20 || particle.x < -20 || particle.y > height) {
				if (streamingConfetti && particles.length <= confetti.maxCount)
					resetParticle(particle, width, height);
				else {
					particles.splice(i, 1);
					i--;
				}
			}
		}
	}
})();

setTimeout(() => confetti.start(), 300);
setTimeout(() => confetti.stop(), 5000);
</script>
<?php endif; ?>
</body>
</html>