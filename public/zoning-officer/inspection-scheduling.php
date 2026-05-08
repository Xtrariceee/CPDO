<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

if (!function_exists('zo_format_datetime')) {
    function zo_format_datetime(?string $value): string
    {
        if (!$value) return 'Not set';
        $ts = strtotime($value);
        return $ts ? date('M d, Y · h:i A', $ts) : $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$applicationId) {
        $_SESSION['flash_error'] = 'No application selected.';
        redirect('zoning-officer/inspection-scheduling.php');
    }
    $application = officer_application($applicationId);
    $order       = payment_order_for_application($applicationId);

    if (!$order || !payment_order_is_verified($order)) {
        $_SESSION['flash_error'] = 'Payment must be verified before scheduling an inspection.';
        redirect('zoning-officer/payment-verification.php?id=' . $applicationId);
    }

    $inspectionDate = trim($_POST['inspection_date'] ?? '');
    $inspectionTime = trim($_POST['inspection_time'] ?? '');

    if ($inspectionDate === '' || $inspectionTime === '') {
        $_SESSION['flash_error'] = 'Inspection date and time are required.';
        redirect('zoning-officer/inspection-scheduling.php?id=' . $applicationId);
    }

    // Check for slot conflict
    $conflictStmt = db()->prepare(
        "SELECT COUNT(*) FROM inspections
         WHERE DATE(scheduled_at) = ? AND TIME_FORMAT(scheduled_at, '%H:%i') = ? AND application_id <> ?"
    );
    $conflictStmt->execute([$inspectionDate, $inspectionTime, $applicationId]);
    if ((int)$conflictStmt->fetchColumn() > 0) {
        $_SESSION['flash_error'] = 'That inspection slot is already taken. Please choose a different date or time.';
        redirect('zoning-officer/inspection-scheduling.php?id=' . $applicationId);
    }

    try {
        $scheduledAt = schedule_inspection_for_application(
            $applicationId,
            $inspectionDate,
            $inspectionTime,
            (int)$user['id']
        );
    } catch (RuntimeException $exception) {
        $_SESSION['flash_error'] = $exception->getMessage();
        redirect('zoning-officer/inspection-scheduling.php?id=' . $applicationId);
    }

    advance_application($applicationId, 'INSPECTION_SCHEDULED', 7);
    notify_user((int)$application['landlord_id'], $applicationId, 'Inspection Scheduled', 'Your CPDO site inspection has been scheduled. Please be available on the scheduled date and time.');
    notify_role(ROLE_TWG, $applicationId, 'New Inspection Assignment', 'A CPDO inspection is ready for TWG field validation.');
    audit_log((int)$user['id'], 'P7_INSPECTION_SCHEDULED', 'applications', $applicationId, ['scheduled_at' => $scheduledAt]);
    $_SESSION['flash_success'] = 'Inspection scheduled successfully.';
    redirect('zoning-officer/inspection-scheduling.php');
}

// Only show applications with verified payment
$allPaid      = officer_applications(['PAID']);
$applications = array_filter($allPaid, function ($app) {
    $order = payment_order_for_application((int)$app['id']);
    return $order && payment_order_is_verified($order);
});

$application     = $applicationId ? officer_application($applicationId) : null;
$order           = $application ? payment_order_for_application((int)$application['id']) : null;
$paymentVerified = $order ? payment_order_is_verified($order) : false;

$scheduledInspections = db()->query(
    'SELECT a.id, a.registry_number, a.property_title, a.property_address, a.phase_status, s.scheduled_at
     FROM inspections s
     INNER JOIN applications a ON a.id = s.application_id
     WHERE s.scheduled_at IS NOT NULL
     ORDER BY s.scheduled_at ASC'
)->fetchAll();

$calendarEvents = [];
foreach ($scheduledInspections as $insp) {
    $ts = $insp['scheduled_at'] ? strtotime($insp['scheduled_at']) : false;
    if (!$ts) continue;
    $calendarEvents[] = [
        'date'     => date('Y-m-d', $ts),
        'time'     => date('H:i', $ts),
        'timeText' => date('h:i A', $ts),
        'registry' => $insp['registry_number'],
        'property' => $insp['property_title'],
        'label'    => $insp['registry_number'] . ' - ' . $insp['property_title'],
    ];
}

$scheduledCount = count($scheduledInspections);
$readyCount     = count($applications);

require __DIR__ . '/../partials/header.php';
?>

<style>
:root{--zo-navy:#0d3154;--zo-blue:#0d6efd;--zo-blue-soft:#eaf3ff;--zo-gold:#f6c343;--zo-green:#198754;--zo-green-soft:#eafaf1;--zo-text:#172033;--zo-muted:#6b7280;--zo-border:#d8e2ef;}
body.zo-page{background:radial-gradient(circle at 1px 1px,rgba(13,110,253,.16) 1px,transparent 0),linear-gradient(180deg,#f7fbff 0%,#edf3fa 100%);background-size:28px 28px,100% 100%;}
.zo-shell{max-width:1180px;margin:0 auto;padding:32px 18px 56px;}
.zo-hero{position:relative;overflow:hidden;border-radius:24px;padding:30px;margin-bottom:22px;background:radial-gradient(circle at top right,rgba(246,195,67,.28),transparent 35%),linear-gradient(135deg,#fff 0%,#f5f9ff 100%);border:1px solid var(--zo-border);box-shadow:0 18px 42px rgba(13,49,84,.10);}
.zo-eyebrow{display:inline-flex;align-items:center;gap:8px;margin-bottom:10px;padding:6px 12px;border-radius:999px;background:var(--zo-blue-soft);color:var(--zo-blue);font-size:.72rem;font-weight:900;letter-spacing:.09em;text-transform:uppercase;}
.zo-eyebrow-dot{width:7px;height:7px;border-radius:50%;background:var(--zo-gold);box-shadow:0 0 0 4px rgba(246,195,67,.22);}
.zo-title{margin:0;color:#071d35;font-size:clamp(1.75rem,4vw,2.55rem);font-weight:900;line-height:1.05;letter-spacing:-.04em;}
.zo-subtitle{max-width:760px;margin:12px 0 0;color:var(--zo-muted);font-size:.98rem;font-weight:500;line-height:1.7;}
.zo-stat-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:24px;}
.zo-stat-card{position:relative;overflow:hidden;min-height:130px;padding:22px;border-radius:20px;background:linear-gradient(145deg,#fff 0%,#f8fbff 100%);border:1px solid var(--zo-border);box-shadow:0 16px 34px rgba(13,49,84,.10);}
.zo-stat-card::before{content:"";position:absolute;top:0;left:0;width:100%;height:4px;background:linear-gradient(90deg,var(--zo-blue),rgba(13,110,253,.15));}
.zo-stat-card--green::before{background:linear-gradient(90deg,var(--zo-green),rgba(25,135,84,.12));}
.zo-stat-label{margin:0 0 9px;color:#071d35;font-size:.78rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;}
.zo-stat-value{margin:0;color:#071d35;font-size:2.3rem;font-weight:950;line-height:1;letter-spacing:-.06em;}
.zo-stat-sub{margin:9px 0 0;color:var(--zo-muted);font-size:.82rem;line-height:1.45;}
.zo-layout{display:grid;grid-template-columns:360px minmax(0,1fr);gap:24px;align-items:start;}
.zo-panel{overflow:hidden;border-radius:20px;background:rgba(255,255,255,.96);border:1px solid var(--zo-border);box-shadow:0 18px 42px rgba(13,49,84,.10);}
.zo-panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:20px 22px;border-bottom:1px solid var(--zo-border);background:#fff;}
.zo-panel-title{margin:0;color:#071d35;font-size:1rem;font-weight:900;}
.zo-panel-sub{margin:4px 0 0;color:var(--zo-muted);font-size:.8rem;line-height:1.5;font-weight:500;}
.zo-panel-body{padding:20px 22px;}
.zo-list{display:grid;gap:10px;}
.zo-app-link{display:block;padding:14px;border-radius:14px;border:1px solid #edf2f8;background:#fff;color:inherit;text-decoration:none;transition:transform .18s,border-color .18s,box-shadow .18s;}
.zo-app-link:hover,.zo-app-link.is-active{color:inherit;transform:translateY(-1px);border-color:#b7cce5;box-shadow:0 12px 24px rgba(13,49,84,.10);}
.zo-app-link.is-active{background:linear-gradient(135deg,#eaf3ff 0%,#fff 100%);}
.zo-app-registry{display:flex;align-items:center;gap:8px;color:#071d35;font-family:ui-monospace,monospace;font-size:.78rem;font-weight:900;margin-bottom:5px;}
.zo-app-registry::before{content:"";width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:var(--zo-blue);box-shadow:0 0 0 4px rgba(13,110,253,.13);}
.zo-app-title{margin:0;color:var(--zo-text);font-size:.88rem;font-weight:800;line-height:1.35;}
.zo-mini-badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 9px;border-radius:999px;font-size:.7rem;font-weight:900;line-height:1;white-space:nowrap;background:var(--zo-blue-soft);color:var(--zo-blue);}
.zo-empty{padding:28px 16px;text-align:center;color:var(--zo-muted);font-size:.86rem;line-height:1.6;}
.zo-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
.zo-form-label{display:block;margin-bottom:7px;color:#071d35;font-size:.78rem;font-weight:900;}
.zo-form-control{width:100%;min-height:42px;padding:10px 12px;border-radius:12px;border:1px solid var(--zo-border);background:#fff;color:var(--zo-text);font-size:.88rem;outline:none;transition:border-color .16s,box-shadow .16s;}
.zo-form-control:focus{border-color:var(--zo-blue);box-shadow:0 0 0 4px rgba(13,110,253,.12);}
.zo-btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:10px 16px;border-radius:12px;font-size:.84rem;font-weight:850;line-height:1;text-decoration:none;border:1px solid transparent;background:var(--zo-blue);color:#fff;box-shadow:0 10px 22px rgba(13,110,253,.24);transition:transform .18s,box-shadow .18s;}
.zo-btn-primary:hover{color:#fff;transform:translateY(-1px);}
.zo-btn-outline{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:10px 16px;border-radius:12px;font-size:.84rem;font-weight:850;line-height:1;text-decoration:none;border:1px solid var(--zo-border);background:#fff;color:var(--zo-navy);transition:transform .18s,border-color .18s;}
.zo-btn-outline:hover{color:var(--zo-navy);background:#f7fbff;border-color:#b9cce2;transform:translateY(-1px);}
.zo-warning{display:none;margin-top:14px;padding:12px 14px;border-radius:12px;background:#fff0f0;color:#9f1239;border:1px solid #fecdd3;font-size:.82rem;font-weight:750;line-height:1.45;}
.zo-warning.is-visible{display:block;}
.zo-note{margin:0;color:var(--zo-muted);font-size:.82rem;line-height:1.55;}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;}
.cal-weekday{text-align:center;font-size:.65rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#62748a;padding-bottom:4px;}
.cal-day{min-height:70px;padding:6px;border-radius:8px;border:1px solid #edf2f8;background:#fff;font-size:.75rem;}
.cal-day.is-muted{opacity:.35;}
.cal-day.is-today{border-color:var(--zo-blue);box-shadow:0 0 0 3px rgba(13,110,253,.1);}
.cal-day-num{font-weight:900;color:#071d35;margin-bottom:3px;}
.cal-event{display:block;padding:2px 5px;border-radius:5px;background:#eaf3ff;color:#1d6aad;font-size:.65rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
@media(max-width:1100px){.zo-layout{grid-template-columns:1fr;}}
@media(max-width:767.98px){.zo-shell{padding:24px 14px 44px;}.zo-form-grid{grid-template-columns:1fr;}.zo-stat-grid{grid-template-columns:1fr;}}
</style>
<script>document.body.classList.add('zo-page');</script>

<div class="zo-shell">
    <section class="zo-hero">
        <div>
            <div class="zo-eyebrow"><span class="zo-eyebrow-dot"></span>Zoning Officer Workspace</div>
            <h1 class="zo-title">Schedule Site Inspection</h1>
            <p class="zo-subtitle">Assign inspection dates and times for applications with verified payments. The calendar shows existing schedules to prevent conflicts.</p>
        </div>
    </section>

    <section class="zo-stat-grid">
        <article class="zo-stat-card">
            <p class="zo-stat-label">Ready to Schedule</p>
            <p class="zo-stat-value"><?= number_format($readyCount) ?></p>
            <p class="zo-stat-sub">Applications with verified payment</p>
        </article>
        <article class="zo-stat-card zo-stat-card--green">
            <p class="zo-stat-label">Scheduled</p>
            <p class="zo-stat-value"><?= number_format($scheduledCount) ?></p>
            <p class="zo-stat-sub">Inspections on the calendar</p>
        </article>
    </section>

    <div class="zo-layout">
        <aside class="d-grid gap-4">
            <section class="zo-panel">
                <div class="zo-panel-header">
                    <div>
                        <h2 class="zo-panel-title">Ready for Scheduling</h2>
                        <p class="zo-panel-sub">Verified payments awaiting inspection date.</p>
                    </div>
                    <span class="zo-mini-badge"><?= $readyCount ?></span>
                </div>
                <div class="zo-panel-body">
                    <?php if ($applications): ?>
                        <div class="zo-list">
                            <?php foreach ($applications as $row): ?>
                                <a class="zo-app-link <?= $application && (int)$application['id'] === (int)$row['id'] ? 'is-active' : '' ?>"
                                   href="inspection-scheduling.php?id=<?= (int)$row['id'] ?>">
                                    <div class="zo-app-registry"><?= e($row['registry_number']) ?></div>
                                    <p class="zo-app-title"><?= e($row['property_title']) ?></p>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="zo-empty">No applications with verified payment ready to schedule.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="zo-panel">
                <div class="zo-panel-header">
                    <div>
                        <h2 class="zo-panel-title">Inspection Calendar</h2>
                        <p class="zo-panel-sub">Existing scheduled inspections.</p>
                    </div>
                </div>
                <div style="padding:20px 22px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <strong id="calendarTitle" style="font-size:.9rem;color:#071d35;"></strong>
                        <div style="display:flex;gap:6px;">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="calendarPrev">&lsaquo;</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="calendarNext">&rsaquo;</button>
                        </div>
                    </div>
                    <div class="cal-grid" id="calendarGrid"></div>
                    <?php if ($scheduledInspections): ?>
                        <div class="d-grid gap-2 mt-3">
                            <?php foreach (array_slice($scheduledInspections, 0, 4) as $insp):
                                $ts = strtotime($insp['scheduled_at']);
                            ?>
                                <div style="font-size:.8rem;padding:8px 10px;border-radius:8px;background:#f4f8fc;border:1px solid #d0dae6;">
                                    <strong style="color:var(--zo-blue);"><?= e($insp['registry_number']) ?></strong><br>
                                    <span style="color:var(--zo-muted);"><?= e(date('M j, Y · h:i A', $ts)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="zo-note mt-3">No inspections scheduled yet.</p>
                    <?php endif; ?>
                </div>
            </section>
        </aside>

        <main class="d-grid gap-4">
            <?php if ($application): ?>
                <?php if (!$paymentVerified): ?>
                    <section class="zo-panel">
                        <div style="padding:22px;">
                            <div class="alert alert-warning mb-0">
                                Payment for <strong><?= e($application['registry_number']) ?></strong> has not been verified yet.
                                <a href="payment-verification.php?id=<?= (int)$application['id'] ?>">Verify payment first &rarr;</a>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="zo-panel" id="schedule-panel">
                        <div style="padding:22px;">
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:18px;flex-wrap:wrap;">
                                <div>
                                    <h2 style="margin:0;color:#071d35;font-size:1.12rem;font-weight:900;">Schedule Inspection</h2>
                                    <p style="margin:5px 0 0;color:var(--zo-muted);font-size:.83rem;">
                                        <?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?>
                                        · <span style="display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;background:#e9f8ee;color:#116d3b;font-size:.7rem;font-weight:900;">Payment Verified</span>
                                    </p>
                                </div>
                            </div>
                            <form method="post" id="scheduleForm">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                                <div class="zo-form-grid">
                                    <div>
                                        <label class="zo-form-label" for="inspectionDate">Inspection Date</label>
                                        <input class="zo-form-control" id="inspectionDate" type="date"
                                               name="inspection_date" required min="<?= e(date('Y-m-d')) ?>">
                                    </div>
                                    <div>
                                        <label class="zo-form-label" for="inspectionTime">Inspection Time</label>
                                        <input class="zo-form-control" id="inspectionTime" type="time"
                                               name="inspection_time" required step="900">
                                    </div>
                                </div>
                                <div class="zo-warning" id="slotWarning">
                                    This date and time already has a scheduled inspection. Please choose a different slot.
                                </div>
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:18px;">
                                    <button class="zo-btn-primary" id="scheduleSubmitBtn">Schedule Inspection</button>
                                    <span class="zo-note">The calendar shows existing slots to help avoid conflicts.</span>
                                </div>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>
            <?php else: ?>
                <section class="zo-panel">
                    <div class="zo-empty">
                        <strong>Select an application</strong><br>
                        Choose an item from the ready-to-schedule list to assign an inspection date and time.
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
(function(){
    var events = <?= json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var grid = document.getElementById('calendarGrid');
    var title = document.getElementById('calendarTitle');
    var cur = new Date(); cur.setDate(1);
    function pad(n){ return String(n).padStart(2,'0'); }
    function key(d){ return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
    function render(){
        if(!grid) return;
        grid.innerHTML='';
        var y=cur.getFullYear(),m=cur.getMonth();
        if(title) title.textContent=cur.toLocaleString('default',{month:'long',year:'numeric'});
        ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(d){
            var el=document.createElement('div'); el.className='cal-weekday'; el.textContent=d; grid.appendChild(el);
        });
        var first=new Date(y,m,1), start=new Date(first);
        start.setDate(first.getDate()-first.getDay());
        var todayKey=key(new Date());
        for(var i=0;i<42;i++){
            var day=new Date(start); day.setDate(start.getDate()+i);
            var dk=key(day);
            var dayEvents=events.filter(function(e){return e.date===dk;});
            var el=document.createElement('div'); el.className='cal-day';
            if(day.getMonth()!==m) el.classList.add('is-muted');
            if(dk===todayKey) el.classList.add('is-today');
            var num=document.createElement('div'); num.className='cal-day-num'; num.textContent=day.getDate(); el.appendChild(num);
            dayEvents.slice(0,2).forEach(function(ev){
                var span=document.createElement('span'); span.className='cal-event';
                span.title=ev.timeText+' · '+ev.label; span.textContent=ev.timeText+' · '+ev.registry; el.appendChild(span);
            });
            grid.appendChild(el);
        }
    }
    document.getElementById('calendarPrev').addEventListener('click',function(){ cur.setMonth(cur.getMonth()-1); render(); });
    document.getElementById('calendarNext').addEventListener('click',function(){ cur.setMonth(cur.getMonth()+1); render(); });
    render();

    var dateIn=document.getElementById('inspectionDate');
    var timeIn=document.getElementById('inspectionTime');
    var warn=document.getElementById('slotWarning');
    var btn=document.getElementById('scheduleSubmitBtn');
    function checkSlot(){
        if(!dateIn||!timeIn||!warn||!btn) return;
        var d=dateIn.value, t=timeIn.value;
        if(!d||!t){ warn.classList.remove('is-visible'); return; }
        var conflict=events.some(function(e){ return e.date===d&&e.time===t; });
        if(conflict){ warn.classList.add('is-visible'); btn.disabled=true; }
        else{ warn.classList.remove('is-visible'); btn.disabled=false; }
    }
    if(dateIn) dateIn.addEventListener('change',checkSlot);
    if(timeIn) timeIn.addEventListener('change',checkSlot);
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
