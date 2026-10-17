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

// Wholesome corporate greetings. {name} is swapped for the first name.
// The pick is seeded by person + date, so it varies between people and days
// but doesn't reshuffle every time the page is refreshed.
function bdayMessage(array $b, bool $today): string {
    $todayMsgs = [
        "Happy Birthday, {name}! 🎉 Thank you for everything you bring to Urban Tradewell Corporation. Wishing you a day full of joy, laughter, and cake! 🎂",
        "Hey {name}, today is your day! 🥳 We're grateful for your hard work and the good energy you bring to the team. Enjoy every moment!",
        "Cheers to you, {name}! 🎈 Your dedication makes a real difference here. May this year bring you health, happiness, and plenty of well-earned rest.",
        "Happy Birthday, {name}! 🎂 The whole Urban Tradewell family is celebrating you today. Thank you for being such a valued part of our team.",
        "Wishing you a wonderful birthday, {name}! 🌟 May your day be filled with good food, good company, and everything you've been hoping for.",
        "It's your special day, {name}! 🎁 Thank you for your commitment and kindness. Here's to another great year ahead!",
        "Happy Birthday, {name}! 🥳 Our team is better because you're in it. Take a break, have some cake, and enjoy the day you deserve.",
        "Many happy returns, {name}! 🎉 We appreciate all that you do. May the year ahead be your best one yet.",
        "Celebrating you today, {name}! 🎈 Thank you for showing up, pitching in, and making work brighter for everyone around you.",
        "Happy Birthday, {name}! 🍰 Wishing you laughter, love, and peace today and always. From all of us at Urban Tradewell Corporation.",
    ];
    $upcomingMsgs = [
        "🎈 {name}'s birthday is just around the corner! Get ready to celebrate and make some unforgettable memories.",
        "🎂 Mark your calendar, team! {name} celebrates a birthday soon. A kind greeting goes a long way!",
        "🎁 {name}'s big day is coming up. Don't forget to send your warm wishes!",
        "🥳 Cake alert! {name}'s birthday is coming soon. Let's make it a memorable one.",
        "🌟 A birthday is on the way for {name}! Take a moment to say thanks for all they do.",
        "🎉 {name} has a birthday coming up. Let's show them some appreciation!",
        "🍰 Heads up, team: {name}'s birthday is around the corner. Wishes and smiles welcome!",
        "🎈 It's almost {name}'s special day! A little kindness makes a big difference.",
    ];
    $pool = $today ? $todayMsgs : $upcomingMsgs;
    $msg  = $pool[abs(crc32(bdayFullName($b) . date('Y-m-d'))) % count($pool)];
    return str_replace('{name}', trim((string) ($b['FirstName'] ?? '')), $msg);
}

function bdayFullName(array $b): string { return trim(($b['FirstName'] ?? '') . ' ' . ($b['LastName'] ?? '')); }
function bdayDate(array $b, string $fmt = 'F j'): string {
    return (isset($b['Birth_date']) && $b['Birth_date'] instanceof DateTime) ? $b['Birth_date']->format($fmt) : '';
}
// Only called for people whose birthday is today, so birth year alone gives the age they're turning.
function bdayAge(array $b): ?int {
    if (!(isset($b['Birth_date']) && $b['Birth_date'] instanceof DateTime)) return null;
    $age = (int) date('Y') - (int) $b['Birth_date']->format('Y');
    return $age > 0 ? $age : null;
}

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
      background: linear-gradient(to right top, #030616, #030624, #020531, #00043d, #030048);
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
    /* ── Redesign: balloons + hero + centered carousel ── */
    .hub-birthday { max-width: 1100px; gap: 3rem; }

    /* Balloons */
    .bd-balloons { position: relative; width: 738px; max-width: 100%; height: 175px; margin: 0 auto; }
    .bd-balloons > div {
      position: absolute; top: 30px; width: 94px; height: 115px; padding: 10px;
      border-radius: 80%; transform-origin: bottom center;
      background: var(--c); color: var(--c); box-shadow: inset 10px 10px 10px var(--s);
    }
    .bd-balloons > div::before { content: "▲"; position: absolute; bottom: -11px; left: 52px; font-size: 1em; }
    .bd-balloons span { display: block; position: relative; top: 30px; font-size: 2.8em; color: #fff; }
    .bd-balloons > div:nth-child(1) { --c: rgba(182,15,97,.9);  --s: rgba(135,11,72,.9);   left: 0;     animation: bdBalloon1 6s ease-in-out infinite; }
    .bd-balloons > div:nth-child(2) { --c: rgba(242,112,45,.9); --s: rgba(222,85,14,.9);   left: 120px; animation: bdBalloon2 6s ease-in-out infinite; }
    .bd-balloons > div:nth-child(3) { --c: rgba(45,181,167,.9); --s: rgba(35,140,129,.9);  left: 240px; animation: bdBalloon4 6s ease-in-out infinite; }
    .bd-balloons > div:nth-child(4) { --c: rgba(190,61,244,.9); --s: rgba(173,14,240,.9);  left: 360px; animation: bdBalloon1 5s ease-in-out infinite; }
    .bd-balloons > div:nth-child(5) { --c: rgba(180,224,67,.9); --s: rgba(158,206,34,.9);  left: 480px; animation: bdBalloon3 5s ease-in-out infinite; }
    .bd-balloons > div:nth-child(6) { --c: rgba(242,194,58,.9); --s: rgba(234,177,15,.9);  left: 600px; animation: bdBalloon2 3s ease-in-out infinite; }
    @keyframes bdBalloon1 { 0%,100% { transform: translateY(0) rotate(-6deg); }        50% { transform: translateY(-20px) rotate(8deg); } }
    @keyframes bdBalloon2 { 0%,100% { transform: translateY(0) rotate(6deg); }         50% { transform: translateY(-30px) rotate(-8deg); } }
    @keyframes bdBalloon3 { 0%,100% { transform: translate(0,-10px) rotate(6deg); }    50% { transform: translate(-20px,30px) rotate(-8deg); } }
    @keyframes bdBalloon4 { 0%,100% { transform: translate(10px,-10px) rotate(-8deg); } 50% { transform: translate(-15px,20px) rotate(10deg); } }
    @media (max-width: 700px) { .bd-balloons { display: none; } }
    @media (prefers-reduced-motion: reduce) { .bd-balloons > div { animation: none; } }

    /* Hero */
    .bd-hero { width: 100%; display: grid; grid-template-columns: minmax(0, 450px) 1fr; gap: 3rem; align-items: center; }
    .bd-hero-photo {
      width: 100%; max-width: 450px; height: 400px; border-radius: 16px; overflow: hidden;
      background: rgba(255,255,255,.08); display: flex; align-items: center; justify-content: center;
    }
    .bd-hero-photo img { width: 100%; height: 100%; object-fit: cover; }
    .bd-hero-photo .bday-avatar-fallback { border-radius: 0; }
    .bd-hero-body h1 { font-family: 'Sora', sans-serif; font-size: clamp(1.8rem, 4vw, 2.6rem); font-weight: 800; line-height: 1.15; color: #fff; margin-bottom: .6rem; }
    .bd-hero-body h1 span { color: var(--blue-bright); }
    .bd-hero-body h5 { font-size: 1.05rem; font-weight: 500; color: #fff; margin-bottom: 1rem; }
    .bd-hero-body p { color: #fff; line-height: 1.7; margin-bottom: 1.25rem; }
    .bd-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem 1.5rem; margin-bottom: 1.5rem; }
    .bd-facts div { color: #fff; font-size: .95rem; }
    .bd-facts i { color: #f59505; margin-right: .5rem; }
    @media (max-width: 991px) {
      .bd-hero { grid-template-columns: 1fr; gap: 1.75rem; justify-items: center; }
      .bd-hero-body { width: 100%; }
    }
    @media (max-width: 575px) { .bd-facts { grid-template-columns: 1fr; } }

    /* Bubble-burst button */
    .bd-btn {
      position: relative; isolation: isolate; padding: 10px 22px; border-radius: 6px;
      border: 1px solid rgba(255,255,255,.25); background: #100572; color: #fff;
      font: inherit; font-weight: 600; cursor: pointer; transition: transform .15s ease;
    }
    .bd-btn:active { transform: scale(.96); }
    .bd-btn::before, .bd-btn::after {
      position: absolute; content: ""; width: 150%; height: 100%; left: 50%;
      transform: translateX(-50%); z-index: -1; background-repeat: no-repeat; pointer-events: none;
    }
    .bd-btn.animate::before {
      top: -70%;
      background-image: radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, transparent 20%, #7d2ae8 20%, transparent 30%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, transparent 10%, #7d2ae8 15%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%);
      background-size: 10% 10%, 20% 20%, 15% 15%, 20% 20%, 18% 18%, 10% 10%, 15% 15%, 10% 10%, 18% 18%;
      animation: bdTopBubbles ease-in-out .6s forwards infinite;
    }
    @keyframes bdTopBubbles {
      0%   { background-position: 5% 90%, 10% 90%, 10% 90%, 15% 90%, 25% 90%, 25% 90%, 40% 90%, 55% 90%, 70% 90%; }
      50%  { background-position: 0% 80%, 0% 20%, 10% 40%, 20% 0%, 30% 30%, 22% 50%, 50% 50%, 65% 20%, 90% 30%; }
      100% { background-position: 0% 70%, 0% 10%, 10% 30%, 20% -10%, 30% 20%, 22% 40%, 50% 40%, 65% 10%, 90% 20%;
             background-size: 0% 0%, 0% 0%, 0% 0%, 0% 0%, 0% 0%, 0% 0%; }
    }
    .bd-btn.animate::after {
      bottom: -70%;
      background-image: radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, transparent 10%, #7d2ae8 15%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%),
        radial-gradient(circle, #7d2ae8 20%, transparent 20%);
      background-size: 15% 15%, 20% 20%, 18% 18%, 20% 20%, 15% 15%, 20% 20%, 18% 18%;
      animation: bdBottomBubbles ease-in-out .6s forwards infinite;
    }
    @keyframes bdBottomBubbles {
      0%   { background-position: 10% -10%, 30% 10%, 55% -10%, 70% -10%, 85% -10%, 70% -10%, 70% 0%; }
      50%  { background-position: 0% 80%, 20% 80%, 45% 60%, 60% 100%, 75% 70%, 95% 60%, 105% 0%; }
      100% { background-position: 0% 90%, 20% 90%, 45% 70%, 60% 110%, 75% 80%, 95% 70%, 110% 10%;
             background-size: 0% 0%, 0% 0%, 0% 0%, 0% 0%, 0% 0%, 0% 0%; }
    }

    /* Upcoming carousel */
    .bd-upcoming { width: 100%; }
    .bd-upcoming-title { text-align: center; color: #fff; font-family: 'Sora', sans-serif; font-size: 1.8rem; font-weight: 700; margin-bottom: 2rem; }
    .bd-carousel { position: relative; overflow: hidden; padding: 4px 0 8px; cursor: grab; user-select: none; touch-action: pan-y; }
    .bd-carousel.dragging { cursor: grabbing; }
    .bd-card img { pointer-events: none; -webkit-user-drag: none; }
    .bd-track { position: relative; display: flex; gap: 24px; transition: transform 1s ease; will-change: transform; }
    .bd-card {
      flex: 0 0 calc((100% - 48px) / 3); display: flex; flex-direction: column;
      background: #030031; border: 1px solid rgba(255,255,255,.08); border-radius: 8px;
      padding: 1.5rem; transition: .5s;
    }
    .bd-card-quote { font-size: 2rem; line-height: 1; color: #fffade; margin-bottom: .75rem; transition: .5s; }
    .bd-card p { flex: 1; color: #eb9e10; font-size: .92rem; line-height: 1.6; margin-bottom: 1.1rem; transition: .5s; }
    .bd-card-person { display: flex; align-items: center; gap: 1rem; }
    .bd-card-avatar { width: 50px; height: 50px; border-radius: 8px; overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.1); }
    .bd-card-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .bd-card-avatar .bday-avatar-fallback { border-radius: 0; }
    .bd-card h5 { margin: 0 0 .2rem; font-size: 1rem; font-weight: 600; color: wheat; transition: .5s; }
    .bd-card small { color: #fffade; transition: .5s; }
    .bd-card.active { background: #f59505; }
    .bd-card.active p, .bd-card.active h5, .bd-card.active small, .bd-card.active .bd-card-quote { color: #030031; }
    .bd-jump .bd-card, .bd-jump .bd-card * { transition: none; }
    .bd-dots { display: flex; justify-content: center; gap: 10px; margin-top: 24px; }
    .bd-dot { width: 12px; height: 12px; padding: 0; border: 0; border-radius: 2px; background: rgba(255,255,255,.25); cursor: pointer; transition: .5s; }
    .bd-dot.active { background: #f59505; }
    @media (max-width: 991px) { .bd-card { flex-basis: calc((100% - 24px) / 2); } }
    @media (max-width: 767px) { .bd-card { flex-basis: 100%; } }
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

  <div class="bd-balloons" aria-hidden="true">
    <div><span>☺</span></div><div><span>B</span></div><div><span>D</span></div>
    <div><span>A</span></div><div><span>Y</span></div><div><span>!</span></div>
  </div>

  <?php if (empty($todayBirthdays) && empty($upcomingBirthdays)): ?>
  <div class="bday-empty">No birthdays in the next 15 days.</div>
  <?php else: ?>
  <div class="hub-birthday">
    <?php if (!empty($todayBirthdays)): ?>
    <?php foreach ($todayBirthdays as $i => $b):
      $first = trim((string) ($b['FirstName'] ?? ''));
      $age   = bdayAge($b);
    ?>
    <div class="bd-hero bday-pop" style="animation-delay: <?= $i * 0.1 ?>s">
      <div class="bd-hero-photo"><?= bdayAvatarMarkup($b, '5rem') ?></div>
      <div class="bd-hero-body">
        <h1>Happy Birthday <span><?= htmlspecialchars($first) ?> !!</span></h1>
        <h5><?= htmlspecialchars(bdayDate($b) ?: date('F j')) ?></h5>
        <p>“<?= htmlspecialchars(bdayMessage($b, true)) ?>”</p>
        <div class="bd-facts">
          <?php if (!empty($b['Position_held'])): ?><div><i class="bi bi-arrow-right"></i><?= htmlspecialchars($b['Position_held']) ?></div><?php endif; ?>
          <?php if (!empty($b['Department'])): ?><div><i class="bi bi-arrow-right"></i><?= htmlspecialchars($b['Department']) ?></div><?php endif; ?>
        </div>
        <button type="button" class="bd-btn">Happy Birthday</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($upcomingBirthdays)): ?>
    <section class="bd-upcoming">
      <h2 class="bd-upcoming-title">Upcoming Birthdays!</h2>
      <div class="bd-carousel" id="bdCarousel">
        <div class="bd-track">
          <?php foreach ($upcomingBirthdays as $b):
            $first = trim((string) ($b['FirstName'] ?? ''));
            $off   = $offsetByKey["{$b['BMonth']}-{$b['Bday']}"] ?? null;
            $when  = $off === 1 ? 'Tomorrow' : ($off ? "In $off days" : '');
            $dateLine = trim(bdayDate($b) . ($when !== '' ? ' · ' . $when : ''), ' ·');
          ?>
          <div class="bd-card">
            <i class="bi bi-quote bd-card-quote"></i>
            <p><?= htmlspecialchars(bdayMessage($b, false)) ?></p>
            <div class="bd-card-person">
              <span class="bd-card-avatar"><?= bdayAvatarMarkup($b, '1rem') ?></span>
              <div>
                <h5><?= htmlspecialchars(bdayFullName($b)) ?></h5>
                <small><?= htmlspecialchars($dateLine) ?></small>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="bd-dots" id="bdDots"></div>
      </div>
    </section>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
<script>
(function () {
  // Bubble burst + confetti replay on the hero button
  document.querySelectorAll('.bd-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.classList.add('animate');
      setTimeout(function () { btn.classList.remove('animate'); }, 600);
      if (typeof confetti !== 'undefined') {
        confetti.start();
        setTimeout(confetti.stop, 3000);
      }
    });
  });

  // Centered carousel (active card turns orange)
  var root = document.getElementById('bdCarousel');
  if (!root) return;
  var track  = root.querySelector('.bd-track');
  var dotsEl = document.getElementById('bdDots');
  var originals = Array.prototype.slice.call(track.children);
  var n = originals.length;
  var infinite = n > 1;
  var idx = 0, timer = null, curShift = 0, dragging = false;

  if (infinite) {
    // Clone the set on both sides so the strip never runs out of cards
    var before = document.createDocumentFragment();
    originals.forEach(function (c) { before.appendChild(c.cloneNode(true)); });
    track.insertBefore(before, track.firstChild);
    originals.forEach(function (c) { track.appendChild(c.cloneNode(true)); });
    idx = n;
  }
  var cards = track.children;

  var dots = [];
  if (infinite) {
    for (var k = 0; k < n; k++) {
      (function (k) {
        var d = document.createElement('button');
        d.type = 'button'; d.className = 'bd-dot'; d.setAttribute('aria-label', 'Birthday ' + (k + 1));
        d.addEventListener('click', function () { go(n + k); restart(); });
        dotsEl.appendChild(d); dots.push(d);
      })(k);
    }
  }

  function place(animate) {
    var card = cards[idx];
    if (!animate) root.classList.add('bd-jump');
    track.style.transition = animate ? '' : 'none';
    curShift = root.clientWidth / 2 - (card.offsetLeft + card.offsetWidth / 2);
    track.style.transform = 'translateX(' + curShift + 'px)';
    for (var i = 0; i < cards.length; i++) cards[i].classList.toggle('active', i === idx);
    dots.forEach(function (d, j) { d.classList.toggle('active', j === ((idx % n) + n) % n); });
    if (!animate) { void track.offsetWidth; track.style.transition = ''; root.classList.remove('bd-jump'); }
  }
  function go(i) { idx = i; place(true); }
  function restart() {
    clearInterval(timer);
    if (infinite && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      timer = setInterval(function () { if (!dragging) go(idx + 1); }, 3500);
    }
  }

  track.addEventListener('transitionend', function (e) {
    if (e.target !== track || !infinite) return;
    if (idx >= 2 * n)   { idx -= n; place(false); }
    else if (idx < n)   { idx += n; place(false); }
  });
  root.addEventListener('mouseenter', function () { clearInterval(timer); });
  root.addEventListener('mouseleave', restart);
  window.addEventListener('resize', function () { place(false); });
  window.addEventListener('load', function () { place(false); });

  // Drag / swipe to move the carousel
  var drag = null;
  root.addEventListener('pointerdown', function (e) {
    if ((e.pointerType === 'mouse' && e.button !== 0) || e.target.closest('.bd-dot')) return;
    clearInterval(timer);
    drag = { x: e.clientX, shift: curShift, id: e.pointerId };
    dragging = true;
    track.style.transition = 'none';
    root.classList.add('dragging');
    root.setPointerCapture(e.pointerId);
  });
  root.addEventListener('pointermove', function (e) {
    if (!drag || e.pointerId !== drag.id) return;
    curShift = drag.shift + (e.clientX - drag.x);
    track.style.transform = 'translateX(' + curShift + 'px)';
  });
  function endDrag(e) {
    if (!drag || e.pointerId !== drag.id) return;
    // Snap to whichever card is closest to the center
    var mid = root.clientWidth / 2, best = idx, bestDist = Infinity;
    for (var i = 0; i < cards.length; i++) {
      var d = Math.abs(cards[i].offsetLeft + cards[i].offsetWidth / 2 + curShift - mid);
      if (d < bestDist) { bestDist = d; best = i; }
    }
    var type = e.pointerType;
    drag = null; dragging = false;
    root.classList.remove('dragging');
    idx = best;
    place(true);
    track.style.transition = 'transform .4s ease';   // quicker snap than the 1s autoplay slide
    if (type !== 'mouse' || !root.matches(':hover')) restart();
  }
  root.addEventListener('pointerup', endDrag);
  root.addEventListener('pointercancel', endDrag);

  place(false);
  restart();
})();
</script>
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