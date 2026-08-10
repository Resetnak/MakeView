<?php

declare(strict_types=1);

// Makeview – line viewer of make targets, services & README per project.
// Single-file PHP server. Mount projects read-only at /projects.

require_once __DIR__ . '/vendor/autoload.php';

use Makeview\Make;
use Makeview\Project;
use Makeview\Value\Credential;

error_reporting(E_ALL & ~E_DEPRECATED); // Parsedown 1.7.4 is noisy on PHP 8.4

define('ROOT', rtrim(getenv('MAKEVIEW_DIR') ?: '/projects', '/'));

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Czech relative time: "před 5 min", "včera", "před 3 dny"… */
function rel_time(int $t): string {
    $d = time() - $t;
    if ($d < 3600) return 'před ' . max(1, intdiv($d, 60)) . ' min';
    if ($d < 86400) return 'před ' . intdiv($d, 3600) . ' h';
    $days = intdiv($d, 86400);
    if ($days === 1) return 'včera';
    if ($days < 31) return "před $days dny";
    if ($days < 365) return 'před ' . intdiv($days, 30) . ' měs.';
    return 'před ' . intdiv($days, 365) . ' r.';
}

/** First real paragraph of a README as plain text, for the featured exhibit. */
function readme_excerpt(string $file, int $max = 220): ?string {
    foreach (preg_split('/\R{2,}/', (string)file_get_contents($file)) as $block) {
        $block = trim($block);
        // skip headings, code fences, tables, images, html
        if ($block === '' || preg_match('/^[#`|!<\[-]/', $block)) continue;
        $text = trim(preg_replace(['/\[([^\]]*)\]\([^)]*\)/', '/[`*_>]/'], ['$1', ''], $block));
        $text = preg_replace('/\s+/', ' ', $text);
        if (mb_strlen($text) < 20) continue;
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
    return null;
}

/** Render one credential copy-and-reveal widget. */
function credential_widget(Credential $c): string
{
    $label = h($c->label);

    if ($c->isPlaceholder) {
        return '<span class="cred cred-ph" title="' . $label . '">' . h($c->value) . '</span>';
    }

    if ($c->kind === 'user') {
        return '<button type="button" class="cred cmd" data-cmd="' . h($c->value) . '"'
            . ' data-tip="Kopírovat ' . $label . '">' . h($c->value) . '</button>';
    }

    $encoded = h(base64_encode($c->value));

    return '<span class="cred cred-secret">'
        . '<span class="cred-mask" data-val="' . $encoded . '">•••••••</span>'
        . '<button type="button" class="cred-eye" aria-label="Zobrazit ' . $label . '" title="Zobrazit">👁</button>'
        . '<button type="button" class="cred-copy" data-val="' . $encoded . '" aria-label="Kopírovat ' . $label . '" title="Kopírovat">⧉</button>'
        . '</span>';
}

/** Render a URL as a clickable link only when the scheme is http(s). */
function url_link(string $url): string
{
    if (preg_match('/^https?:\/\//i', $url) !== 1) {
        return '<span class="hint">' . h($url) . '</span>';
    }

    return '<a class="url" href="' . h($url) . '" target="_blank" rel="noopener noreferrer">' . h($url) . '</a>';
}

// ---- routing ----
$projects = Project::scan(ROOT);
$sel = $_GET['p'] ?? null;
if (!is_string($sel) || !isset($projects[$sel])) $sel = null; // whitelist: block traversal; is_string blocks ?p[]=

?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Makeview<?= $sel ? ' · ' . h($sel) : '' ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' rx='3' fill='%230f1412'/><text x='3' y='12' font-family='monospace' font-size='11' fill='%2356c48d'>$</text></svg>">
<style>
  :root {
    --bg:#f4f5f3; --panel:#fbfcfa; --ink:#191d1b; --muted:#68716c;
    --line:color-mix(in oklab, var(--ink) 13%, transparent);
    --hairline:color-mix(in oklab, var(--ink) 8%, transparent);
    --accent:#0e7a4d; --star:#b7791f;
    --code-bg:color-mix(in oklab, var(--ink) 5%, transparent);
    --tint:color-mix(in oklab, var(--accent) 9%, transparent);
    --dot:color-mix(in oklab, var(--ink) 9%, transparent);
    --r:6px;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg:#0b0d0c; --panel:#101312; --ink:#dde3df; --muted:#7f8a84;
      --accent:#56c48d; --star:#d9a53f;
      --dot:color-mix(in oklab, var(--ink) 8%, transparent);
    }
  }
  * { box-sizing:border-box; }
  html { scroll-behavior:smooth; }
  body { margin:0; color:var(--ink); background:var(--bg);
         background-image:radial-gradient(var(--dot) 1px, transparent 1px);
         background-size:24px 24px; background-attachment:fixed;
         font:14px/1.65 ui-monospace,"SF Mono","JetBrains Mono",Menlo,Consolas,monospace;
         font-feature-settings:"calt","liga"; font-variant-numeric:tabular-nums;
         display:flex; min-height:100dvh; -webkit-font-smoothing:antialiased; }
  ::selection { background:color-mix(in oklab, var(--accent) 28%, transparent); }
  :focus-visible { outline:2px solid var(--accent); outline-offset:2px; border-radius:3px; }

  /* ---- sidebar ---- */
  aside { width:264px; flex:none; padding:22px 14px 28px; position:sticky; top:0; height:100dvh;
          overflow:auto; border-right:1px solid var(--line); background:var(--panel);
          scrollbar-width:thin; }
  aside h1 { font-size:14px; font-weight:600; letter-spacing:-.01em; margin:0 8px 24px;
             display:flex; align-items:baseline; gap:2px; }
  aside h1::before { content:"$ "; color:var(--accent); font-weight:400; }
  aside h1 b { color:var(--accent); font-weight:600; }
  aside h1::after { content:"▊"; color:var(--accent); margin-left:5px; font-weight:400;
                    animation:blink 1.2s step-end infinite; }
  @keyframes blink { 50% { opacity:0; } }
  @media (prefers-reduced-motion: reduce) {
    aside h1::after { animation:none; }
    html { scroll-behavior:auto; }
  }
  .proj { display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:4px;
          color:var(--muted); text-decoration:none; font-size:13px; position:relative;
          border-left:2px solid transparent; transition:background .12s, color .12s; }
  .proj:hover { background:var(--code-bg); color:var(--ink); }
  .proj:active { transform:translateY(1px); }
  .proj.active { background:var(--tint); color:var(--accent); border-left-color:var(--accent); }
  .proj .nm { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .star { cursor:pointer; opacity:.2; font-size:13px; line-height:1; user-select:none;
          transition:opacity .12s; }
  .star:hover { opacity:.65; }
  .star.on { opacity:1; color:var(--star); }
  .grouphdr { font-size:10px; font-weight:500; text-transform:uppercase; letter-spacing:.14em;
              color:var(--muted); margin:20px 10px 6px; }
  .grouphdr::before { content:"# "; color:color-mix(in oklab, var(--muted) 55%, transparent); }

  /* ---- main ---- */
  main { flex:1; padding:48px clamp(24px,4vw,64px) 72px; max-width:1180px; min-width:0; }
  .hint { color:var(--muted); }
  h2.title { margin:0 0 8px; font-size:24px; font-weight:600; letter-spacing:-.02em; }
  h2.title::before { content:"./"; color:var(--muted); font-weight:400; }
  h2.title.home::before { content:"~/"; }
  .meta { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:36px; }
  .path { color:var(--muted); font-size:12px; }
  .badge { font-size:10px; text-transform:uppercase; letter-spacing:.1em; color:var(--muted);
           border:1px solid var(--line); border-radius:3px; padding:1.5px 7px; }
  .badge.on { color:var(--accent); border-color:color-mix(in oklab, var(--accent) 35%, transparent); }

  /* stat / widget tiles */
  .stats { display:flex; flex-wrap:wrap; gap:12px; margin:28px 0; }
  .stat { border:1px solid var(--line); border-radius:var(--r); background:var(--panel);
          padding:12px 18px; min-width:130px; }
  .stat b { display:block; font-size:20px; font-weight:600; letter-spacing:-.02em;
            color:var(--accent); line-height:1.4; white-space:nowrap; }
  .stat span { font-size:10.5px; text-transform:uppercase; letter-spacing:.12em; color:var(--muted); }

  /* home: featured exhibit + catalog index */
  .colophon { color:var(--muted); font-size:12px; margin:0 0 6px; }
  .featured { margin:10px 0 4px; }
  .featured .fname { display:inline-block; font-size:clamp(26px, 4.5vw, 44px); font-weight:600;
                     letter-spacing:-.02em; line-height:1.15; color:var(--ink);
                     text-decoration:none; text-wrap:balance; transition:color .12s; }
  .featured .fname::before { content:"./"; color:var(--muted); font-weight:400; }
  .featured .fname:hover { color:var(--accent); }
  .featured .fmeta { display:flex; flex-wrap:wrap; align-items:center; gap:6px 12px;
                     margin:10px 0 0; font-size:12px; color:var(--muted); }
  .featured .fmeta .br::before { content:"⎇ "; }
  .featured .fexcerpt { max-width:62ch; margin:14px 0 16px; text-wrap:pretty;
                        color:color-mix(in oklab, var(--ink) 82%, transparent);
                        font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
                        font-size:15px; }
  .rows { border-top:1px solid var(--line); margin:8px 0 0; }
  .row { display:flex; align-items:baseline; gap:14px; padding:11px 10px;
         border-bottom:1px solid var(--hairline); text-decoration:none; color:var(--ink);
         transition:background .12s; }
  .row:hover { background:var(--tint); }
  .row:active { transform:translateY(1px); }
  .row .rname { font-size:15px; font-weight:600; letter-spacing:-.01em; white-space:nowrap; }
  .row .rname::before { content:"./"; color:var(--muted); font-weight:400; }
  .row .rmeta { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis;
                white-space:nowrap; color:var(--muted); font-size:12px; }
  .row .rtime { color:var(--muted); font-size:12px; white-space:nowrap; }
  @media (max-width:720px) { .row .rmeta { display:none; } }

  /* sections styled as Makefile comments */
  .sect { font-size:11px; font-weight:500; text-transform:uppercase; letter-spacing:.13em;
          color:var(--muted); margin:38px 0 10px; display:flex; align-items:center; gap:10px; }
  .sect::before { content:"##"; color:var(--accent); letter-spacing:0; }
  .sect::after { content:""; flex:1; height:1px; background:var(--hairline); }

  table.cmds { width:100%; border-collapse:separate; border-spacing:0; margin:8px 0 8px;
               background:var(--panel); border:1px solid var(--line); border-radius:var(--r);
               overflow:hidden; }
  table.cmds td { padding:10px 16px; border-top:1px solid var(--hairline); vertical-align:top; }
  table.cmds tr:first-child td { border-top:none; }
  table.cmds tr { position:relative; }
  table.cmds tr:hover td { background:var(--tint); }
  table.cmds td:first-child { width:1%; white-space:nowrap; }
  .cmd { display:inline-flex; align-items:center; white-space:nowrap; cursor:pointer;
         color:var(--accent); font-size:13px; position:relative;
         padding:2px 8px 2px 6px; border-radius:4px; transition:background .12s;
         border:1px solid transparent;
         appearance:none; background:none; font-family:inherit; }
  .cmd::before { content:"$ "; color:var(--muted); }
  table.cmds .cmd { padding:0; border:none; }
  .bare .cmd { border-color:var(--line); background:var(--panel); }
  .bare .cmd:hover { border-color:color-mix(in oklab, var(--accent) 40%, transparent);
                     background:var(--tint); }
  .cmd:active { transform:translateY(1px); }
  .cmd::after { content:attr(data-tip); position:absolute; left:50%; translate:-50% 0; top:-30px;
                font-size:11px; background:var(--ink); color:var(--bg);
                padding:3px 8px; border-radius:4px; opacity:0; pointer-events:none;
                transition:opacity .12s, translate .12s; white-space:nowrap; }
  .cmd:hover::after { opacity:1; translate:-50% -3px; }
  .cmd.copied { color:var(--accent); }
  .cmd.copied::after { opacity:1; background:var(--accent);
                       color:color-mix(in oklab, var(--bg) 92%, black); }
  .desc { color:color-mix(in oklab, var(--ink) 80%, transparent); font-size:13px; }
  .bare { display:flex; flex-wrap:wrap; gap:8px; margin:8px 0; }
  .empty { color:var(--muted); font-style:italic; margin:6px 0 24px; font-size:13px; }
  .empty::before { content:"∅ "; font-style:normal; }

  /* services + readme links */
  .svc-name { font-weight:600; }
  .svc-url { white-space:nowrap; }
  .svc-creds { text-align:right; white-space:nowrap; }
  .url { color:var(--accent); text-decoration:none;
         text-underline-offset:3px; text-decoration-line:underline;
         text-decoration-color:color-mix(in oklab, var(--accent) 35%, transparent); }
  .url:hover { text-decoration-color:var(--accent); }

  .cred { display:inline-flex; align-items:center; gap:4px; margin-left:10px;
          font-size:13px; vertical-align:middle; }
  .cred.cmd { margin-left:10px; }
  .cred-ph { color:var(--muted); font-style:italic; }
  .cred-secret { display:inline-flex; align-items:center; gap:4px; padding:2px 6px;
                 border:1px solid var(--line); border-radius:4px;
                 background:var(--panel); }
  .cred-mask { font-family:inherit; letter-spacing:.08em; color:var(--muted); }
  .cred-mask.shown { color:var(--ink); letter-spacing:0; }
  .cred-eye, .cred-copy { border:none; background:none; cursor:pointer; opacity:.6;
                           font-size:11px; line-height:1; padding:2px; }
  .cred-eye:hover, .cred-copy:hover { opacity:1; }
  .cred-copy.copied { opacity:1; color:var(--accent); }

  .links { border-top:1px solid var(--line); margin:8px 0 0; }
  .lrow { padding:11px 10px; border-bottom:1px solid var(--hairline); }
  .lhead { display:flex; gap:12px; align-items:baseline; }
  .llabel { font-size:15px; font-weight:600; letter-spacing:-.01em; }
  .lctx { flex:1; text-align:right; color:var(--muted); font-size:12px;
          overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .lbody { display:flex; margin-top:4px; flex-wrap:wrap; align-items:center; gap:2px 4px; }
  .lbody .cred:first-child { margin-left:0; }

  @media (max-width:720px) {
    .svc-creds { text-align:left; white-space:normal; }
    .cred-eye, .cred-copy { padding:8px 6px; font-size:13px; }
    .lctx { display:none; }
  }

  /* README: prose in sans for readability, code stays mono */
  .readme { margin-top:10px; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
            font-size:15px; }
  .readme > * { max-width:72ch; }
  .readme h1,.readme h2,.readme h3 { letter-spacing:-.01em; text-wrap:balance; }
  .readme h1,.readme h2 { border-bottom:1px solid var(--hairline); padding-bottom:.3em; }
  .readme img { max-width:100%; height:auto; border-radius:4px; }
  .readme pre { background:var(--panel); padding:14px 16px; border-radius:var(--r);
                overflow-x:auto; border:1px solid var(--line); font-size:13px; max-width:none; }
  .readme code { font-family:ui-monospace,"SF Mono","JetBrains Mono",Menlo,Consolas,monospace;
                 background:var(--code-bg); padding:.15em .4em; border-radius:3px; font-size:.86em; }
  .readme pre code { background:none; padding:0; font-size:inherit; }
  .readme table { border-collapse:collapse; display:block; overflow-x:auto; max-width:none; }
  .readme table td, .readme table th { border:1px solid var(--line); padding:7px 12px; }
  .readme a { color:var(--accent); text-underline-offset:3px;
              text-decoration-color:color-mix(in oklab, var(--accent) 40%, transparent); }
  .readme a:hover { text-decoration-color:var(--accent); }
  .readme blockquote { margin:1em 0; padding:2px 16px; border-left:2px solid var(--accent);
                       color:var(--muted); }

  @media (max-width:720px) {
    body { flex-direction:column; }
    /* sidebar collapses into one horizontally scrollable strip */
    aside { width:100%; height:auto; position:static; border-right:none;
            border-bottom:1px solid var(--line); display:flex; align-items:center;
            overflow-x:auto; padding:12px 14px; scrollbar-width:none; }
    aside h1 { margin:0 14px 0 0; white-space:nowrap; }
    #pinned, #all { display:contents; }
    .grouphdr { display:none; }
    .proj { flex:none; border-left:none; }
    main { padding-top:28px; }
    /* touch targets */
    .bare .cmd { padding:8px 12px 8px 10px; }
    .row { padding:14px 10px; }
  }
</style>
</head>
<body>
<aside>
  <h1>make<b>view</b></h1>
  <div id="pinned"></div>
  <div id="all">
    <?php foreach ($projects as $name => $p): ?>
      <a class="proj<?= $sel === $name ? ' active' : '' ?>" href="?p=<?= h(rawurlencode($name)) ?>" data-name="<?= h($name) ?>">
        <span class="star" data-name="<?= h($name) ?>" role="button" tabindex="0"
              aria-label="Připnout <?= h($name) ?>" aria-pressed="false" title="Připnout">★</span>
        <span class="nm"><?= h($name) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</aside>

<main>
<?php if ($sel === null):
  $nMk = count(array_filter($projects, fn($p) => $p['makefile']));
  $nRd = count(array_filter($projects, fn($p) => $p['readme']));
  // catalog: enrich + sort by last activity; freshest project is the featured exhibit
  $cards = [];
  foreach ($projects as $name => $p) {
    $m = Project::meta(ROOT . '/' . $name);
    $m['targets'] = $p['makefile'] ? count(Make::parseTargets((string)file_get_contents($p['makefile']))['documented']) : 0;
    $cards[$name] = $m;
  }
  uasort($cards, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
  $featName = array_key_first($cards);
  $featTargets = [];
  $featExcerpt = null;
  $featServices = [];
  if ($featName !== null) {
    $fp = $projects[$featName];
    if ($fp['makefile']) $featTargets = array_slice(Make::parseTargets((string)file_get_contents($fp['makefile']))['documented'], 0, 3);
    if ($fp['readme']) $featExcerpt = readme_excerpt($fp['readme']);
    $featServices = Project::services(ROOT . '/' . $featName);
  }
?>
  <h2 class="title home">makeview</h2>
  <p class="colophon"><?= count($projects) ?> projektů · <?= $nMk ?>× Makefile · <?= $nRd ?>× README</p>

  <?php if ($featName !== null): $fm = $cards[$featName]; ?>
    <h3 class="sect">Právě na stole</h3>
    <section class="featured">
      <a class="fname" href="?p=<?= h(rawurlencode($featName)) ?>"><?= h($featName) ?></a>
      <div class="fmeta">
        <span title="<?= date('j. n. Y H:i', $fm['mtime']) ?>"><?= rel_time($fm['mtime']) ?></span>
        <?php if ($fm['branch']): ?><span class="br"><?= h($fm['branch']) ?></span><?php endif; ?>
        <?php foreach ($fm['stack'] as $s): ?><span class="badge"><?= $s ?></span><?php endforeach; ?>
      </div>
      <?php if ($featExcerpt): ?><p class="fexcerpt"><?= h($featExcerpt) ?></p><?php endif; ?>
      <?php if ($featTargets): ?>
        <div class="bare">
          <?php foreach ($featTargets as $c): ?>
            <button type="button" class="cmd" data-cmd="make <?= h($c['target']) ?>" data-tip="<?= h($c['desc']) ?>">make <?= h($c['target']) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($featServices): $firstUrl = null; foreach ($featServices as $s) { if ($s->url !== null) { $firstUrl = $s->url; break; } } ?>
        <?php if ($firstUrl !== null): ?>
          <p class="fmeta"><?= url_link($firstUrl) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (count($cards) > 1): ?>
    <h3 class="sect">Katalog</h3>
    <div class="rows">
      <?php foreach ($cards as $name => $m): if ($name === $featName) continue; ?>
        <a class="row" href="?p=<?= h(rawurlencode($name)) ?>">
          <span class="rname"><?= h($name) ?></span>
          <span class="rmeta"><?= h(implode(' · ', array_filter([
            $m['branch'] ? '⎇ ' . $m['branch'] : null,
            $m['targets'] ? $m['targets'] . '× make' : null,
            $m['stack'] ? implode(' + ', $m['stack']) : null,
          ]))) ?></span>
          <span class="rtime" title="<?= date('j. n. Y H:i', $m['mtime']) ?>"><?= rel_time($m['mtime']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php elseif ($featName === null): ?>
    <p class="empty">Žádné projekty s Makefile nebo README.</p>
  <?php endif; ?>
<?php else:
  $p = $projects[$sel];
  $parsed = $p['makefile'] ? Make::parseTargets((string)file_get_contents($p['makefile'])) : ['documented' => [], 'bare' => []];
  $m = Project::meta(ROOT . '/' . $sel);
?>
  <h2 class="title"><?= h($sel) ?></h2>
  <div class="meta">
    <span class="path"><?= h(str_replace(ROOT, '~/…', dirname($p['makefile'] ?? $p['readme']))) ?></span>
    <span class="badge<?= $p['makefile'] ? ' on' : '' ?>">Makefile</span>
    <span class="badge<?= $p['readme'] ? ' on' : '' ?>">README</span>
    <?php foreach ($m['stack'] as $s): ?><span class="badge"><?= $s ?></span><?php endforeach; ?>
  </div>
  <div class="stats">
    <div class="stat"><b title="<?= date('j. n. Y H:i', $m['mtime']) ?>"><?= rel_time($m['mtime']) ?></b><span>poslední aktivita</span></div>
    <?php if ($m['branch']): ?><div class="stat"><b>⎇ <?= h($m['branch']) ?></b><span>větev</span></div><?php endif; ?>
    <?php if ($p['makefile']): ?><div class="stat"><b><?= count($parsed['documented']) + count($parsed['bare']) ?></b><span>make targetů</span></div><?php endif; ?>
  </div>

  <?php if ($p['makefile']): ?>
    <h3 class="sect">Make příkazy</h3>
    <?php if ($parsed['documented']): ?>
      <table class="cmds">
        <?php foreach ($parsed['documented'] as $c): ?>
          <tr>
            <td><button type="button" class="cmd" data-cmd="make <?= h($c['target']) ?>" data-tip="Kopírovat">make <?= h($c['target']) ?></button></td>
            <td class="desc"><?= h($c['desc']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p class="empty">Žádné cíle s `## popiskem`.</p>
    <?php endif; ?>

    <?php if ($parsed['bare']): ?>
      <h3 class="sect">Ostatní cíle</h3>
      <div class="bare">
        <?php foreach ($parsed['bare'] as $t): ?>
          <button type="button" class="cmd" data-cmd="make <?= h($t) ?>" data-tip="Kopírovat">make <?= h($t) ?></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <p class="empty">Projekt nemá Makefile.</p>
  <?php endif; ?>

  <?php
    $services = Project::services(ROOT . '/' . $sel);
    $composeFailed = Project::composeFailed(ROOT . '/' . $sel);
  ?>
  <?php if ($composeFailed): ?>
    <h3 class="sect">Služby</h3>
    <p class="empty">compose.yml se nepodařilo zpracovat.</p>
  <?php elseif ($services): ?>
    <h3 class="sect">Služby</h3>
    <table class="cmds">
      <?php foreach ($services as $s): ?>
        <tr>
          <td class="svc-name"><?= h($s->name) ?></td>
          <td class="svc-url">
            <?php if ($s->url !== null): ?>
              <?= url_link($s->url) ?>
              <span class="hint"><?= h($s->urlSource === 'traefik' ? 'traefik' : 'localhost') ?></span>
            <?php else: ?>
              <span class="hint">—</span>
            <?php endif; ?>
          </td>
          <td class="svc-creds">
            <?php foreach ($s->credentials as $c): ?><?= credential_widget($c) ?><?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php $links = $p['readme'] ? Project::readmeLinks($p['readme']) : []; ?>
  <?php if ($links): ?>
    <h3 class="sect">Odkazy z README</h3>
    <div class="links">
      <?php foreach ($links as $l): ?>
        <div class="lrow">
          <div class="lhead">
            <span class="llabel"><?= h($l->label) ?></span>
            <?php if ($l->context !== ''): ?><span class="lctx"><?= h($l->context) ?></span><?php endif; ?>
          </div>
          <div class="lbody">
            <?php if ($l->url !== null): ?><?= url_link($l->url) ?><?php endif; ?>
            <?php foreach ($l->credentials as $c): ?><?= credential_widget($c) ?><?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($p['readme']):
    $pd = new Parsedown();
    $pd->setSafeMode(true);
  ?>
    <h3 class="sect">README</h3>
    <div class="readme"><?= $pd->text(file_get_contents($p['readme'])) ?></div>
  <?php endif; ?>
<?php endif; ?>
</main>

<script>
// Copy-on-click for commands.
document.addEventListener('click', e => {
  const c = e.target.closest('.cmd');
  if (!c) return;
  navigator.clipboard?.writeText(c.dataset.cmd);
  c.dataset.origTip ??= c.dataset.tip;
  c.classList.add('copied'); c.dataset.tip = 'Zkopírováno';
  setTimeout(() => { c.classList.remove('copied'); c.dataset.tip = c.dataset.origTip; }, 1000);
});

// Credential reveal + copy. Values are base64 in data-val so they don't shout
// at view-source; this is a screen-sharing convenience, not a secret store.
document.addEventListener('click', e => {
  const eye = e.target.closest('.cred-eye');
  if (eye) {
    const mask = eye.parentElement.querySelector('.cred-mask');
    const shown = mask.classList.toggle('shown');
    mask.textContent = shown ? atob(mask.dataset.val) : '•••••••';
    eye.setAttribute('aria-label', (shown ? 'Skrýt' : 'Zobrazit'));
    return;
  }

  const copy = e.target.closest('.cred-copy');
  if (!copy) return;
  navigator.clipboard?.writeText(atob(copy.dataset.val));
  copy.classList.add('copied');
  setTimeout(() => copy.classList.remove('copied'), 1000);
});

// Re-mask everything when focus leaves the page — avoids a revealed password
// lingering on screen after switching away.
window.addEventListener('blur', () => {
  document.querySelectorAll('.cred-mask.shown').forEach(m => {
    m.classList.remove('shown');
    m.textContent = '•••••••';
  });
});

// Pinned favorites in localStorage — reorders a pinned copy into the top section.
const KEY = 'makeview.pins';
const pins = new Set(JSON.parse(localStorage.getItem(KEY) || '[]'));
function save() { localStorage.setItem(KEY, JSON.stringify([...pins])); }
function render() {
  document.querySelectorAll('.proj').forEach(a => {
    const n = a.dataset.name;
    const s = a.querySelector('.star');
    s.classList.toggle('on', pins.has(n));
    s.setAttribute('aria-pressed', pins.has(n));
    s.setAttribute('aria-label', (pins.has(n) ? 'Odepnout ' : 'Připnout ') + n);
  });
  const box = document.getElementById('pinned');
  box.innerHTML = pins.size ? '<div class="grouphdr">Připnuté</div>' : '';
  [...pins].sort().forEach(n => {
    const src = document.querySelector(`#all .proj[data-name="${CSS.escape(n)}"]`);
    if (src) box.appendChild(src.cloneNode(true));
  });
  if (pins.size) box.insertAdjacentHTML('afterend', '<div class="grouphdr" id="allhdr">Všechny</div>');
}
document.addEventListener('click', e => {
  const s = e.target.closest('.star');
  if (!s) return;
  e.preventDefault(); e.stopPropagation();
  const n = s.dataset.name;
  pins.has(n) ? pins.delete(n) : pins.add(n);
  save();
  document.getElementById('allhdr')?.remove();
  render();
});
// Keyboard support for the star (role=button span).
document.addEventListener('keydown', e => {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.closest?.('.star')) {
    e.preventDefault();
    e.target.closest('.star').click();
  }
});
render();
</script>
</body>
</html>
