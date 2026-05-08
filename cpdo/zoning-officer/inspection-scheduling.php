<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

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

    try {
        $scheduledAt = schedule_inspection_for_application(
            $applicationId,
            trim($_POST['inspection_date'] ?? ''),
            trim($_POST['inspection_time'] ?? ''),
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

// Only show applications with verified payment (ready to schedule)
$allPaid   = officer_applications(['PAID']);
$applications = array_filter($allPaid, function ($app) {
    $order = payment_order_for_application((int)$app['id']);
    return $order && payment_order_is_verified($order);
});

$application     = $applicationId ? officer_application($applicationId) : null;
$order           = $application ? payment_order_for_application((int)$application['id']) : null;
$paymentVerified = $order ? payment_order_is_verified($order) : false;

// Scheduled inspections for the calendar
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

require __DIR__ . '/../partials/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="index.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <div>
        <h1 class="h3 mb-0">Schedule Site Inspection</h1>
    </div>
</div>

<div class="row g-4">
    <!-- Application list -->
    <div class="col-lg-3">
        <div class="gov-card p-3 mb-3">
            <p class="eyebrow mb-2">Ready to Schedule</p>
            <?php if ($applications): ?>
                <div class="d-grid gap-1">
                    <?php foreach ($applications as $row): ?>
                        <?php $isActive = $application && (int)$application['id'] === (int)$row['id']; ?>
                        <a class="app-list-item <?= $isActive ? 'app-list-item--active' : '' ?>"
                           href="inspection-scheduling.php?id=<?= (int)$row['id'] ?>">
                            <span class="app-list-registry"><?= e($row['registry_number']) ?></span>
                            <span class="app-list-title"><?= e($row['property_title']) ?></span>
                            <span class="app-list-landlord"><?= e($row['landlord_name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-secondary small mb-0">No applications with verified payment ready to schedule.</p>
            <?php endif; ?>
        </div>

        <!-- Mini upcoming list -->
        <?php if ($scheduledInspections): ?>
        <div class="gov-card p-3">
            <p class="eyebrow mb-2">Upcoming Inspections</p>
            <div class="d-grid gap-2">
                <?php foreach (array_slice($scheduledInspections, 0, 5) as $insp):
                    $ts = strtotime($insp['scheduled_at']);
                ?>
                    <div style="font-size:.8rem;padding:8px 10px;border-radius:8px;background:#f4f8fc;border:1px solid #d0dae6;">
                        <strong style="color:var(--cpdo-blue);"><?= e($insp['registry_number']) ?></strong><br>
                        <span style="color:var(--cpdo-muted);"><?= e(date('M j, Y · h:i A', $ts)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main content -->
    <div class="col-lg-9">
        <?php if ($application): ?>
            <?php if (!$paymentVerified): ?>
                <div class="gov-card p-4 mb-4">
                    <div class="alert alert-warning mb-0">
                        Payment for <strong><?= e($application['registry_number']) ?></strong> has not been verified yet.
                        <a href="payment-verification.php?id=<?= (int)$application['id'] ?>">Verify payment first &rarr;</a>
                    </div>
                </div>
            <?php else: ?>
                <section class="gov-card p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <h2 class="h5 mb-1">Schedule Inspection</h2>
                            <p class="small text-secondary mb-0">
                                <?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?>
                                · <span class="badge text-bg-success">Payment Verified</span>
                            </p>
                        </div>
                    </div>
                    <form method="post" id="scheduleForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Inspection Date</label>
                                <input class="form-control" id="inspectionDate" type="date"
                                       name="inspection_date" required min="<?= e(date('Y-m-d')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Inspection Time</label>
                                <input class="form-control" id="inspectionTime" type="time"
                                       name="inspection_time" required step="900">
                            </div>
                        </div>
                        <div id="slotWarning" class="alert alert-danger mt-3 d-none">
                            This date and time already has a scheduled inspection. Please choose a different slot.
                        </div>
                        <button class="btn btn-primary mt-3" id="scheduleSubmitBtn">
                            Schedule Inspection
                        </button>
                        <p class="small text-secondary mt-2 mb-0">
                            The calendar on the left shows existing scheduled inspections to help avoid conflicts.
                        </p>
                    </form>
                </section>
            <?php endif; ?>

            <!-- Inspection calendar -->
            <section class="gov-card p-4">
                <h2 class="h5 mb-3">Inspection Calendar</h2>
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h3 class="h6 mb-0" id="calendarTitle"></h3>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="calendarPrev">&lsaquo;</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="calendarNext">&rsaquo;</button>
                    </div>
                </div>
                <div id="calendarGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;"></div>
            </section>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">
                Select an application from the list to schedule its site inspection.
            </div>

            <!-- Show calendar even with no selection -->
            <section class="gov-card p-4 mt-4">
                <h2 class="h5 mb-3">Inspection Calendar</h2>
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h3 class="h6 mb-0" id="calendarTitle"></h3>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="calendarPrev">&lsaquo;</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="calendarNext">&rsaquo;</button>
                    </div>
                </div>
                <div id="calendarGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;"></div>
            </section>
        <?php endif; ?>
    </div>
</div>

<style>
.app-list-item{display:block;padding:10px 12px;border-radius:var(--radius-sm);text-decoration:none;border:1px solid transparent;transition:background var(--transition-fast),border-color var(--transition-fast);}
.app-list-item:hover{background:var(--cpdo-light);border-color:rgba(47,128,199,.2);}
.app-list-item--active{background:var(--cpdo-light);border-color:var(--cpdo-blue);}
.app-list-registry{display:block;font-size:var(--text-xs);font-weight:900;color:var(--cpdo-blue);letter-spacing:.04em;}
.app-list-title{display:block;font-size:var(--text-sm);font-weight:700;color:var(--cpdo-deep);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.app-list-landlord{display:block;font-size:var(--text-xs);color:var(--cpdo-muted);margin-top:1px;}
.cal-day{min-height:70px;padding:6px;border-radius:8px;border:1px solid #edf2f8;background:#fff;font-size:.75rem;}
.cal-day.is-muted{opacity:.35;}
.cal-day.is-today{border-color:var(--cpdo-blue);box-shadow:0 0 0 3px rgba(29,106,173,.1);}
.cal-day-num{font-weight:900;color:#071d35;margin-bottom:3px;}
.cal-event{display:block;padding:2px 5px;border-radius:5px;background:#eaf3ff;color:#1d6aad;font-size:.65rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.cal-weekday{text-align:center;font-size:.65rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#62748a;padding-bottom:4px;}
</style>

<script>
(function () {
    var events = <?= json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var grid = document.getElementById('calendarGrid');
    var title = document.getElementById('calendarTitle');
    var cur = new Date(); cur.setDate(1);

    function pad(n) { return String(n).padStart(2, '0'); }
    function key(d) { return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }

    function render() {
        if (!grid) return;
        grid.innerHTML = '';
        var y = cur.getFullYear(), m = cur.getMonth();
        if (title) title.textContent = cur.toLocaleString('default', {month:'long', year:'numeric'});
        ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(d) {
            var el = document.createElement('div');
            el.className = 'cal-weekday'; el.textContent = d; grid.appendChild(el);
        });
        var first = new Date(y, m, 1);
        var start = new Date(first); start.setDate(first.getDate() - first.getDay());
        var todayKey = key(new Date());
        for (var i = 0; i < 42; i++) {
            var day = new Date(start); day.setDate(start.getDate() + i);
            var dk = key(day);
            var dayEvents = events.filter(function(e) { return e.date === dk; });
            var el = document.createElement('div');
            el.className = 'cal-day';
            if (day.getMonth() !== m) el.classList.add('is-muted');
            if (dk === todayKey) el.classList.add('is-today');
            var num = document.createElement('div');
            num.className = 'cal-day-num'; num.textContent = day.getDate(); el.appendChild(num);
            dayEvents.slice(0, 2).forEach(function(ev) {
                var span = document.createElement('span');
                span.className = 'cal-event';
                span.title = ev.timeText + ' · ' + ev.label;
                span.textContent = ev.timeText + ' · ' + ev.registry;
                el.appendChild(span);
            });
            grid.appendChild(el);
        }
    }

    document.getElementById('calendarPrev').addEventListener('click', function() {
        cur.setMonth(cur.getMonth() - 1); render();
    });
    document.getElementById('calendarNext').addEventListener('click', function() {
        cur.setMonth(cur.getMonth() + 1); render();
    });
    render();

    // Slot conflict check
    var dateIn = document.getElementById('inspectionDate');
    var timeIn = document.getElementById('inspectionTime');
    var warn   = document.getElementById('slotWarning');
    var btn    = document.getElementById('scheduleSubmitBtn');

    function checkSlot() {
        if (!dateIn || !timeIn || !warn || !btn) return;
        var d = dateIn.value, t = timeIn.value;
        if (!d || !t) { warn.classList.add('d-none'); return; }
        var conflict = events.some(function(e) { return e.date === d && e.time === t; });
        if (conflict) { warn.classList.remove('d-none'); btn.disabled = true; }
        else          { warn.classList.add('d-none');    btn.disabled = false; }
    }
    if (dateIn) dateIn.addEventListener('change', checkSlot);
    if (timeIn) timeIn.addEventListener('change', checkSlot);
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
