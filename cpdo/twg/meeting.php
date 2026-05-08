<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$applicationId) {
        $_SESSION['flash_error'] = 'No application selected.';
        redirect('twg/meeting.php');
    }
    $application = officer_application($applicationId);
    $existing    = latest_meeting_for_application($applicationId);

    // Combine separate date + time into a single datetime
    $meetingDate = trim($_POST['meeting_date'] ?? '');
    $meetingTime = trim($_POST['meeting_time'] ?? '');
    $meetingAt   = ($meetingDate !== '' && $meetingTime !== '')
        ? $meetingDate . ' ' . $meetingTime . ':00'
        : ($meetingDate !== '' ? $meetingDate : null);

    if ($existing) {
        db()->prepare('UPDATE meetings SET scheduled_at = ?, minutes = ?, created_by = ? WHERE id = ?')
           ->execute([$meetingAt, $_POST['minutes'] ?? '', (int)$user['id'], (int)$existing['id']]);
    } else {
        db()->prepare('INSERT INTO meetings (application_id, scheduled_at, minutes, created_by) VALUES (?, ?, ?, ?)')
           ->execute([$applicationId, $meetingAt, $_POST['minutes'] ?? '', (int)$user['id']]);
    }

    advance_application($applicationId, 'DELIBERATION', 11);
    notify_user(
        (int)$application['landlord_id'],
        $applicationId,
        'TWG Meeting Conducted',
        'The TWG meeting for your application has been conducted. Your application is now under deliberation and a decision will be recorded shortly.'
    );
    audit_log((int)$user['id'], 'P11_MEETING_DISCUSSIONS_LOGGED', 'applications', $applicationId);
    $_SESSION['flash_success'] = 'Meeting discussions and clarifications logged.';
    redirect('twg/meeting.php?id=' . $applicationId);
}

$applications = officer_applications(['FOR_MEETING']);
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$meeting      = $application ? latest_meeting_for_application((int)$application['id']) : null;

// Scheduled inspections for the calendar
$scheduledInspections = db()->query(
    'SELECT a.id, a.registry_number, a.property_title, s.scheduled_at, s.scheduled_date, s.scheduled_time
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

// Parse existing meeting date/time for pre-filling the split inputs
$existingMeetingDate = '';
$existingMeetingTime = '';
if ($meeting && $meeting['scheduled_at']) {
    $ts = strtotime($meeting['scheduled_at']);
    if ($ts) {
        $existingMeetingDate = date('Y-m-d', $ts);
        $existingMeetingTime = date('H:i', $ts);
    }
}

require __DIR__ . '/../partials/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="index.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <div>
        <h1 class="h3 mb-0">Meeting Module</h1>
    </div>
</div>

<div class="row g-4">
    <!-- Left: application list + calendar -->
    <div class="col-lg-4">
        <div class="gov-card p-3 mb-3">
            <p class="eyebrow mb-2">For Meeting</p>
            <?php if ($applications): ?>
                <div class="d-grid gap-1">
                    <?php foreach ($applications as $row): ?>
                        <?php $isActive = $application && (int)$application['id'] === (int)$row['id']; ?>
                        <a class="app-list-item <?= $isActive ? 'app-list-item--active' : '' ?>"
                           href="meeting.php?id=<?= (int)$row['id'] ?>">
                            <span class="app-list-registry"><?= e($row['registry_number']) ?></span>
                            <span class="app-list-title"><?= e($row['property_title']) ?></span>
                            <span class="app-list-landlord"><?= e($row['landlord_name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-secondary small mb-0">No applications ready for meeting minutes.</p>
            <?php endif; ?>
        </div>

        <!-- Inspection Calendar -->
        <div class="gov-card p-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <p class="eyebrow mb-0">Inspection Calendar</p>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="calendarPrev">&lsaquo;</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="calendarNext">&rsaquo;</button>
                </div>
            </div>
            <p class="fw-bold small mb-2" id="calendarTitle"></p>
            <div class="cal-grid" id="calendarGrid"></div>

            <?php if ($scheduledInspections): ?>
                <div class="d-grid gap-2 mt-3">
                    <?php foreach (array_slice($scheduledInspections, 0, 5) as $insp):
                        $ts = strtotime($insp['scheduled_at']);
                        $dispDate = !empty($insp['scheduled_date'])
                            ? date('M j, Y', strtotime($insp['scheduled_date']))
                            : date('M j, Y', $ts);
                        $dispTime = !empty($insp['scheduled_time'])
                            ? date('h:i A', strtotime($insp['scheduled_time']))
                            : date('h:i A', $ts);
                    ?>
                        <div style="font-size:.8rem;padding:8px 10px;border-radius:8px;background:#f4f8fc;border:1px solid #d0dae6;">
                            <strong style="color:var(--cpdo-blue,#1d6aad);"><?= e($insp['registry_number']) ?></strong><br>
                            <span style="color:#0b2a4a;font-weight:700;"><?= e($dispDate) ?></span>
                            <span style="color:#62748a;"> · <?= e($dispTime) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="small text-secondary mt-2 mb-0">No inspections scheduled yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: meeting form -->
    <div class="col-lg-8">
        <?php if ($application): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">

                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <h2 class="h5 mb-1"><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></h2>
                        <p class="small text-secondary mb-0"><?= e($application['landlord_name']) ?></p>
                    </div>
                    <span class="badge text-bg-primary">For Meeting</span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Meeting Date</label>
                        <input class="form-control" type="date" name="meeting_date"
                               value="<?= e($existingMeetingDate) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Meeting Time</label>
                        <input class="form-control" type="time" name="meeting_time"
                               value="<?= e($existingMeetingTime) ?>" step="900">
                    </div>
                </div>

                <label class="form-label fw-bold">Discussions, Clarifications, and Minutes</label>
                <textarea class="form-control mb-3" name="minutes" rows="8" required
                          placeholder="Record the meeting discussions, clarifications raised, and official minutes."><?= e($meeting['minutes'] ?? '') ?></textarea>

                <button class="btn btn-primary">Save Meeting Record</button>
            </form>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">
                Select an application from the list to record meeting minutes.
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.app-list-item{display:block;padding:10px 12px;border-radius:var(--radius-sm,6px);text-decoration:none;border:1px solid transparent;transition:background .15s,border-color .15s;}
.app-list-item:hover{background:var(--cpdo-light,#eef2f7);border-color:rgba(47,128,199,.2);}
.app-list-item--active{background:var(--cpdo-light,#eef2f7);border-color:var(--cpdo-blue,#1d6aad);}
.app-list-registry{display:block;font-size:.72rem;font-weight:900;color:var(--cpdo-blue,#1d6aad);letter-spacing:.04em;}
.app-list-title{display:block;font-size:.85rem;font-weight:700;color:var(--cpdo-deep,#0b2a4a);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.app-list-landlord{display:block;font-size:.72rem;color:var(--cpdo-muted,#62748a);margin-top:1px;}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;}
.cal-weekday{text-align:center;font-size:.62rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#62748a;padding-bottom:3px;}
.cal-day{min-height:52px;padding:4px 5px;border-radius:6px;border:1px solid #edf2f8;background:#fff;font-size:.72rem;}
.cal-day.is-muted{opacity:.32;}
.cal-day.is-today{border-color:var(--cpdo-blue,#1d6aad);box-shadow:0 0 0 2px rgba(29,106,173,.12);}
.cal-day-num{font-weight:900;color:#071d35;margin-bottom:2px;font-size:.72rem;}
.cal-event{display:block;padding:1px 4px;border-radius:4px;background:#eaf3ff;color:#1d6aad;font-size:.6rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;}
</style>

<script>
(function () {
    var events = <?= json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var grid  = document.getElementById('calendarGrid');
    var title = document.getElementById('calendarTitle');
    var cur   = new Date(); cur.setDate(1);

    function pad(n) { return String(n).padStart(2, '0'); }
    function key(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    function render() {
        if (!grid) return;
        grid.innerHTML = '';
        var y = cur.getFullYear(), m = cur.getMonth();
        if (title) title.textContent = cur.toLocaleString('default', { month: 'long', year: 'numeric' });

        ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(function (d) {
            var el = document.createElement('div');
            el.className = 'cal-weekday';
            el.textContent = d;
            grid.appendChild(el);
        });

        var first = new Date(y, m, 1);
        var start = new Date(first);
        start.setDate(first.getDate() - first.getDay());
        var todayKey = key(new Date());

        for (var i = 0; i < 42; i++) {
            var day = new Date(start);
            day.setDate(start.getDate() + i);
            var dk = key(day);
            var dayEvents = events.filter(function (e) { return e.date === dk; });

            var el = document.createElement('div');
            el.className = 'cal-day';
            if (day.getMonth() !== m) el.classList.add('is-muted');
            if (dk === todayKey)      el.classList.add('is-today');

            var num = document.createElement('div');
            num.className = 'cal-day-num';
            num.textContent = day.getDate();
            el.appendChild(num);

            dayEvents.slice(0, 1).forEach(function (ev) {
                var span = document.createElement('span');
                span.className = 'cal-event';
                span.title = ev.timeText + ' · ' + ev.label;
                span.textContent = ev.registry;
                el.appendChild(span);
            });

            grid.appendChild(el);
        }
    }

    document.getElementById('calendarPrev').addEventListener('click', function () {
        cur.setMonth(cur.getMonth() - 1); render();
    });
    document.getElementById('calendarNext').addEventListener('click', function () {
        cur.setMonth(cur.getMonth() + 1); render();
    });

    render();
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
