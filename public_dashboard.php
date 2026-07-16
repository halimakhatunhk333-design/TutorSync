<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


session_start();

// Example: if you already track logged-in users, you can redirect them here.
// if (isset($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$tutorRegisterLink  = "registration.php?role=tutor";
$studentLoginLink   = "registration.php?role=student";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TutorSync — Learning, Synced</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --navy:#152238;
    --navy-deep:#0d1626;
    --ivory:#F6F3EC;
    --ivory-deep:#EDE8DC;
    --gold:#C9982F;
    --gold-soft:#E4C87A;
    --teal:#2F6F6B;
    --charcoal:#20232A;
    --line:#D8D2C4;
    --line-dark:rgba(246,243,236,0.18);
  }

  *{ box-sizing:border-box; margin:0; padding:0; }

  html,body{ height:100%; }

  body{
    font-family:'Inter', sans-serif;
    color:var(--charcoal);
    background:var(--ivory);
    overflow-x:hidden;
  }

  /* ---------- Top bar ---------- */
  .topbar{
    position:fixed;
    top:0; left:0; right:0;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:22px 0 0;
    z-index:20;
    pointer-events:none;
  }
.brand{
    font-family:'Fraunces', serif;
    font-weight:600;
    font-size:1.35rem;
    letter-spacing:0.02em;
    color:#fff;
    display:flex;
    align-items:center;
    gap:10px;
    text-shadow:0 1px 12px rgba(0,0,0,0.25);
}
.brand-logo{
    width:30px;
    height:30px;
    flex-shrink:0;
    filter:drop-shadow(0 2px 6px rgba(0,0,0,0.3));
}
.brand-name{
    color:var(--gold-soft);
}
.brand-name em{
    font-style:normal;
    color:#fff;
}
.brand-tagline{
    font-family:'Inter', sans-serif;
    font-weight:500;
    font-size:0.62rem;
    letter-spacing:0.22em;
    text-transform:uppercase;
    color:var(--teal);
    background:rgba(246,243,236,0.9);
    padding:3px 8px;
    border-radius:2px;
    margin-left:2px;
    opacity:0.95;
}

  /* ---------- Split screen fork ---------- */
  .fork{
    position:relative;
    min-height:100vh;
    display:flex;
    flex-wrap:wrap;
  }

  .panel{
    position:relative;
    flex:1 1 50%;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:80px 56px;
    text-decoration:none;
    color:inherit;
    overflow:hidden;
    transition:flex-grow 0.55s cubic-bezier(.22,.61,.36,1);
  }


  .fork:hover .panel.tutor{ flex-grow:1.12; }
  .fork:hover .panel.tutor:hover{ flex-grow:1.28; }
  .fork:hover .panel.student{ flex-grow:1.12; }
  .fork:hover .panel.student:hover{ flex-grow:1.28; }

  .panel.tutor{
    background:
      radial-gradient(1100px 700px at 20% 15%, rgba(201,152,47,0.10), transparent 60%),
      linear-gradient(160deg, var(--navy) 0%, var(--navy-deep) 100%);
    color:var(--ivory);
    justify-content:flex-end;
  }

  .panel.student{
    background:
      radial-gradient(1100px 700px at 80% 85%, rgba(47,111,107,0.10), transparent 60%),
      linear-gradient(160deg, var(--ivory) 0%, var(--ivory-deep) 100%);
    color:var(--charcoal);
    justify-content:flex-start;
  }

  .panel-inner{
    max-width:420px;
    width:100%;
    position:relative;
    z-index:2;
  }

  .eyebrow{
    font-size:0.72rem;
    letter-spacing:0.24em;
    text-transform:uppercase;
    font-weight:600;
    margin-bottom:18px;
    display:inline-flex;
    align-items:center;
    gap:10px;
  }
  .panel.tutor .eyebrow{ color:var(--gold-soft); }
  .panel.student .eyebrow{ color:var(--teal); }

  .eyebrow::before{
    content:"";
    width:26px; height:1px;
    background:currentColor;
    display:inline-block;
  }

  .panel h1{
    font-family:'Fraunces', serif;
    font-weight:500;
    font-size:clamp(2rem, 3.4vw, 2.9rem);
    line-height:1.08;
    margin-bottom:20px;
  }

  .panel p.desc{
    font-size:0.98rem;
    line-height:1.6;
    max-width:360px;
    margin-bottom:32px;
  }
  .panel.tutor p.desc{ color:rgba(246,243,236,0.72); }
  .panel.student p.desc{ color:rgba(32,35,42,0.68); }

  .cta{
    display:inline-flex;
    align-items:center;
    gap:10px;
    font-weight:600;
    font-size:0.95rem;
    padding:15px 30px;
    border-radius:2px;
    text-decoration:none;
    transition:transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
    letter-spacing:0.01em;
  }
  .panel.tutor .cta{
    background:var(--gold);
    color:var(--navy-deep);
  }
  .panel.tutor .cta:hover{
    background:var(--gold-soft);
    transform:translateY(-2px);
    box-shadow:0 10px 24px rgba(201,152,47,0.28);
  }
  .panel.student .cta{
    background:var(--teal);
    color:var(--ivory);
  }
  .panel.student .cta:hover{
    background:#265D59;
    transform:translateY(-2px);
    box-shadow:0 10px 24px rgba(47,111,107,0.24);
  }
  .cta svg{ width:16px; height:16px; transition:transform 0.25s ease; }
  .cta:hover svg{ transform:translateX(3px); }

  .stat-row{
    display:flex;
    gap:28px;
    margin-top:36px;
    padding-top:24px;
    border-top:1px solid;
  }
  .panel.tutor .stat-row{ border-color:var(--line-dark); }
  .panel.student .stat-row{ border-color:rgba(32,35,42,0.14); }

  .stat-row div b{
    font-family:'Fraunces', serif;
    font-size:1.25rem;
    display:block;
    font-weight:500;
  }
  .stat-row div span{
    font-size:0.72rem;
    text-transform:uppercase;
    letter-spacing:0.08em;
    opacity:0.65;
  }

  /* ---------- Center seam (book-spine) ---------- */
  .seam{
    position:absolute;
    top:0; bottom:0;
    left:50%;
    width:1px;
    background:linear-gradient(to bottom, transparent, rgba(255,255,255,0.35) 15%, rgba(255,255,255,0.35) 85%, transparent);
    z-index:5;
    transform:translateX(-50%);
    pointer-events:none;
  }
  .seam-badge{
    position:absolute;
    top:50%;
    left:50%;
    transform:translate(-50%,-50%);
    z-index:10;
    width:74px; height:74px;
    border-radius:50%;
    background:var(--ivory);
    border:1px solid var(--line);
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 8px 28px rgba(21,34,56,0.22);
    font-family:'Fraunces', serif;
    font-weight:600;
    font-size:0.72rem;
    letter-spacing:0.05em;
    text-align:center;
    color:var(--navy);
    line-height:1.15;
    pointer-events:none;
  }

  /* ---------- Footer note ---------- */
.footnote{
    position:fixed;
    bottom:20px;
    left:0; right:0;
    text-align:center;
    font-size:0.75rem;
    letter-spacing:0.04em;
    color:rgba(255,255,255,0.55);
    z-index:20;
    pointer-events:none;
}
.rights-text{
    color:var(--teal);
}
.student-tagline{
    font-family:'Inter', sans-serif;
    font-weight:500;
    font-size:0.62rem;
    letter-spacing:0.22em;
    text-transform:uppercase;
    color:var(--navy);
    background:rgba(255,255,255,0.85);
    padding:3px 8px;
    border-radius:2px;
    display:inline-block;
    margin-bottom:6px;
}

  /* ---------- Responsive ---------- */
  @media (max-width: 860px){
    .fork{ flex-direction:column; }
    .panel{ flex:1 1 auto; min-height:52vh; padding:64px 32px; justify-content:center !important; }
    .fork:hover .panel.tutor,
    .fork:hover .panel.student,
    .fork:hover .panel.tutor:hover,
    .fork:hover .panel.student:hover{ flex-grow:1; }
    .panel-inner{ max-width:100%; }
    .seam{ left:0; right:0; top:50%; bottom:auto; width:auto; height:1px;
      background:linear-gradient(to right, transparent, rgba(255,255,255,0.35) 15%, rgba(255,255,255,0.35) 85%, transparent); }
    .seam-badge{ top:50%; left:50%; }
    .topbar{ padding-top:18px; }
  }

  /* ---------- Accessibility ---------- */
  a.panel:focus-visible, a.cta:focus-visible{
    outline:2px solid var(--gold-soft);
    outline-offset:3px;
  }
  @media (prefers-reduced-motion: reduce){
    .panel, .cta, .cta svg{ transition:none !important; }
  }
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">
        <svg class="brand-logo" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M20 4L4 12L20 20L36 12L20 4Z" fill="#C9982F"/>
            <path d="M11 16.5V26C11 26 14.5 30 20 30C25.5 30 29 26 29 26V16.5" stroke="#2F6F6B" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M33 13V21" stroke="#F6F3EC" stroke-width="2.2" stroke-linecap="round"/>
            <circle cx="33" cy="23.5" r="1.6" fill="#F6F3EC"/>
        </svg>
        <span class="brand-name">Tutor<em>Sync</em></span>
        <span class="brand-tagline">Learning, Synced</span>
    </div>
</div>

  <main class="fork" aria-label="Choose your path on TutorSync">

    <a href="<?php echo htmlspecialchars($tutorRegisterLink); ?>" class="panel tutor">
      <div class="panel-inner">
        <div class="eyebrow">For Educators</div>
        <h1>Become a&nbsp;Tutor</h1>
        <p class="desc">Build your teaching profile, set your own availability, and get matched with students who need exactly what you teach.</p>
        <span class="cta">
          Create tutor profile
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </span>
       
      </div>
    </a>

    <a href="<?php echo htmlspecialchars($studentLoginLink); ?>" class="panel student">
      <div class="panel-inner">
        <div class="eyebrow">For Learners &amp; Families</div>
        <h1>Student&nbsp;/ Guardian</h1>
        <p class="desc">Book sessions, track attendance and progress, and stay in sync with your tutor's schedule — all from one dashboard.</p>
        <span class="cta">
          Continue as student
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </span>

      </div>
    </a>

    <div class="seam" aria-hidden="true"></div>
    <div class="seam-badge" aria-hidden="true">CHOOSE<br>YOUR PATH</div>
  </main>

  <div class="footnote">&copy; <?php echo date("Y"); ?> TutorSync. <span class="rights-text">All rights reserved.</span></div>

</body>
</html>
