<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
verify_csrf();

if (!function_exists('twg_minutes_fields')) {
    function twg_minutes_fields(): array {
        return [
            'association_name'  => 'Name of Association',
            'meeting_type'      => 'Type of Meeting',
            'meeting_date'      => 'Date',
            'meeting_time'      => 'Time',
            'facilitator'       => 'Meeting Facilitator',
            'meeting_link'      => 'Video Conference Link / Venue',
            'invitees'          => 'Invitees',
            'call_to_order'     => 'Call to Order',
            'roll_call'         => 'Roll Call',
            'attendees_present' => 'Attendees Present',
            'absent'            => 'Absent',
            'agenda_reports'    => 'a. Reports',
            'open_issues'       => 'b. Open Issues',
            'remarks'           => 'Remarks',
            'response'          => 'Response',
            'special_notes'     => 'c. Special Notes',
            'adjournment'       => 'd. Adjournment',
            'prepared_by'       => 'Prepared by',
            'approved_by'       => 'Approved',
            'noted_by'          => 'Noted',
        ];
    }
}

if (!function_exists('twg_default_minutes_data')) {
    function twg_default_minutes_data(?array $application = null, ?array $user = null): array {
        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        return [
            'association_name'  => 'LZRC TWG',
            'meeting_type'      => 'Virtual',
            'meeting_date'      => '',
            'meeting_time'      => '',
            'facilitator'       => $fullName,
            'meeting_link'      => '',
            'invitees'          => trim(($application['landlord_name'] ?? '') ?: ($application['account_name'] ?? '')),
            'call_to_order'     => '',
            'roll_call'         => '',
            'attendees_present' => '',
            'absent'            => '',
            'agenda_reports'    => '',
            'open_issues'       => '',
            'remarks'           => '',
            'response'          => '',
            'special_notes'     => '',
            'adjournment'       => '',
            'prepared_by'       => $fullName,
            'approved_by'       => '',
            'noted_by'          => '',
        ];
    }
}

if (!function_exists('twg_extract_minutes_value')) {
    function twg_extract_minutes_value(string $text, string $label, array $allLabels): string {
        if (trim($text) === '') return '';
        $nextLabels  = array_filter($allLabels, fn($item) => $item !== $label);
        $nextPattern = implode('|', array_map(fn($item) => preg_quote($item . ':', '/'), $nextLabels));
        $pattern = '/^' . preg_quote($label . ':', '/') . '[ \t]*(.*?)(?=^\s*(?:' . $nextPattern . ')\s*|\z)/ms';
        if (preg_match($pattern, $text, $matches)) return trim($matches[1]);
        return '';
    }
}

if (!function_exists('twg_html_date_value')) {
    function twg_html_date_value(?string $value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}

if (!function_exists('twg_html_time_value')) {
    function twg_html_time_value(?string $value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (preg_match('/^\d{2}:\d{2}/', $value)) return substr($value, 0, 5);
        $ts = strtotime($value);
        return $ts ? date('H:i', $ts) : '';
    }
}

if (!function_exists('twg_minutes_data_from_meeting')) {
    function twg_minutes_data_from_meeting(?array $meeting, ?array $application = null, ?array $user = null): array {
        $data = twg_default_minutes_data($application, $user);
        if (!$meeting) return $data;
        $text   = (string)($meeting['minutes'] ?? '');
        $fields = twg_minutes_fields();
        $labels = array_values($fields);
        $parsedAny = false;
        foreach ($fields as $key => $label) {
            $value = twg_extract_minutes_value($text, $label, $labels);
            if ($value !== '') { $data[$key] = $value; $parsedAny = true; }
        }
        if (!$parsedAny && trim($text) !== '') $data['agenda_reports'] = trim($text);
        if (!empty($meeting['scheduled_at'])) {
            $ts = strtotime($meeting['scheduled_at']);
            if ($ts) {
                if ($data['meeting_date'] === '') $data['meeting_date'] = date('Y-m-d', $ts);
                if ($data['meeting_time'] === '') $data['meeting_time'] = date('H:i', $ts);
            }
        }
        $data['meeting_date'] = twg_html_date_value($data['meeting_date']);
        $data['meeting_time'] = twg_html_time_value($data['meeting_time']);
        return $data;
    }
}

if (!function_exists('twg_minutes_data_from_post')) {
    function twg_minutes_data_from_post(array $post, array $existingData): array {
        $data = $existingData;
        foreach (twg_minutes_fields() as $key => $label) {
            if (array_key_exists($key, $post)) $data[$key] = trim((string)$post[$key]);
        }
        return $data;
    }
}

if (!function_exists('twg_build_minutes_text')) {
    function twg_build_minutes_text(array $data): string {
        $lines = ['MINUTES OF MEETING', ''];
        $singleLine = ['association_name','meeting_type','meeting_date','meeting_time','facilitator','meeting_link','prepared_by','approved_by','noted_by'];
        foreach (twg_minutes_fields() as $key => $label) {
            if ($key === 'agenda_reports') { $lines[] = ''; $lines[] = 'MEETING AGENDA'; $lines[] = ''; }
            $value = trim((string)($data[$key] ?? ''));
            if (in_array($key, $singleLine, true)) {
                $lines[] = $label . ': ' . ($value !== '' ? $value : 'N/A');
            } else {
                $lines[] = $label . ':';
                $lines[] = $value !== '' ? $value : 'N/A';
            }
            $lines[] = '';
        }
        return trim(implode("\n", $lines));
    }
}

if (!function_exists('twg_meeting_slot_taken')) {
    function twg_meeting_slot_taken(string $date, string $time, int $excludeApplicationId = 0): bool {
        if ($date === '' || $time === '') return false;
        try {
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM meetings
                 WHERE scheduled_at IS NOT NULL
                   AND DATE(scheduled_at) = ?
                   AND TIME_FORMAT(scheduled_at, '%H:%i') = ?
                   AND application_id <> ?"
            );
            $stmt->execute([$date, $time, $excludeApplicationId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('twg_all_meetings')) {
    function twg_all_meetings(): array {
        try {
            $stmt = db()->query(
                "SELECT m.*, a.id AS application_id, a.registry_number, a.property_title, a.account_name
                 FROM meetings m
                 INNER JOIN applications a ON a.id = m.application_id
                 WHERE m.scheduled_at IS NOT NULL
                 ORDER BY m.scheduled_at ASC"
            );
            return $stmt->fetchAll();
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('twg_initials')) {
    function twg_initials(string $text): string {
        $text = trim($text);
        if ($text === '') return 'MT';
        $parts    = preg_split('/\s+/', $text);
        $initials = '';
        foreach ($parts as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
            if (strlen($initials) >= 2) break;
        }
        return $initials ?: 'MT';
    }
}

if (!function_exists('twg_pdf_safe_text')) {
    function twg_pdf_safe_text(string $text): string {
        $search  = ["\xe2\x80\x94", "\xe2\x80\x93", "\xe2\x80\x9c", "\xe2\x80\x9d",
                    "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\xa2", "\xc2\xb7", "\xe2\x82\xb1"];
        $replace = ['-', '-', '"', '"', "'", "'", '*', '-', 'PHP'];
        $text    = str_replace($search, $replace, $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E\r\n\t]/', '?', $text);
    }
}

if (!function_exists('twg_pdf_escape')) {
    function twg_pdf_escape(string $text): string {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        return $text;
    }
}

if (!function_exists('twg_pdf_wrap_lines')) {
    function twg_pdf_wrap_lines(string $text, int $maxChars = 92): array {
        $result = [];
        foreach (preg_split("/\r\n|\r|\n/", twg_pdf_safe_text($text)) as $line) {
            $line = rtrim($line);
            if ($line === '') { $result[] = ''; continue; }
            while (strlen($line) > $maxChars) {
                $cut = strrpos(substr($line, 0, $maxChars + 1), ' ');
                if ($cut === false || $cut < 25) $cut = $maxChars;
                $result[] = rtrim(substr($line, 0, $cut));
                $line = ltrim(substr($line, $cut));
            }
            $result[] = $line;
        }
        return $result;
    }
}

if (!function_exists('twg_output_minutes_pdf')) {
    function twg_output_minutes_pdf(string $filename, string $title, string $body): void {
        $lines   = twg_pdf_wrap_lines($title . "\n\n" . $body, 92);
        $chunks  = array_chunk($lines, 48) ?: [[]];
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pageIds = [];
        foreach ($chunks as $chunk) {
            $content  = "BT\n/F1 10 Tf\n54 748 Td\n14 TL\n";
            foreach ($chunk as $line) $content .= '(' . twg_pdf_escape($line) . ") Tj\nT*\n";
            $content .= "ET\n";
            $cId = count($objects) + 1;
            $objects[$cId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $pId = count($objects) + 1;
            $objects[$pId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$cId} 0 R >>";
            $pageIds[] = $pId . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageIds) . '] /Count ' . count($pageIds) . ' >>';
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $count   = count($objects);
        for ($i = 1; $i <= $count; $i++) { $offsets[$i] = strlen($pdf); $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($count + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $count; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . ($count + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf; exit;
    }
}

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

// ── PDF download ──────────────────────────────────────────────────────────────
if (isset($_GET['download_minutes_pdf']) && $applicationId > 0) {
    $application = officer_application($applicationId);
    $meeting     = $application ? latest_meeting_for_application($applicationId) : null;
    if (!$application || !$meeting) { http_response_code(404); exit('Meeting minutes not found.'); }
    $minutesData = twg_minutes_data_from_meeting($meeting, $application, $user);
    $minutesText = trim((string)($meeting['minutes'] ?? ''));
    if ($minutesText === '') $minutesText = twg_build_minutes_text($minutesData);
    $safeReg  = preg_replace('/[^A-Za-z0-9_-]+/', '-', $application['registry_number'] ?? ('app-' . $applicationId));
    $filename = 'minutes-of-meeting-' . trim($safeReg, '-') . '.pdf';
    $title    = "CPDO LAND PORTAL\nLZRC TWG MINUTES OF MEETING\n\nRegistry: " . ($application['registry_number'] ?? 'N/A') . "\nProperty: " . ($application['property_title'] ?? 'N/A') . "\nGenerated: " . date('Y-m-d H:i:s');
    twg_output_minutes_pdf($filename, $title, $minutesText);
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$applicationId) { $_SESSION['flash_error'] = 'No application selected.'; redirect('twg/meeting.php'); }
    $application  = officer_application($applicationId);
    $existing     = latest_meeting_for_application($applicationId);
    $existingData = twg_minutes_data_from_meeting($existing, $application, $user);
    $minutesData  = twg_minutes_data_from_post($_POST, $existingData);
    $meetingDate  = twg_html_date_value($minutesData['meeting_date'] ?? '');
    $meetingTime  = twg_html_time_value($minutesData['meeting_time'] ?? '');
    $minutesData['meeting_date'] = $meetingDate;
    $minutesData['meeting_time'] = $meetingTime;
    $meetingAt = ($meetingDate !== '' && $meetingTime !== '') ? $meetingDate . ' ' . $meetingTime . ':00' : null;

    if (in_array($action, ['schedule_meeting', 'save_minutes'], true) && $meetingDate !== '' && $meetingTime !== '') {
        if (twg_meeting_slot_taken($meetingDate, $meetingTime, $applicationId)) {
            $_SESSION['flash_error'] = 'That meeting date and time is already scheduled for another application.';
            redirect('twg/meeting.php?id=' . $applicationId . '#meeting-management');
        }
    }

    $minutesText = twg_build_minutes_text($minutesData);
    if ($existing) {
        db()->prepare('UPDATE meetings SET scheduled_at = ?, minutes = ?, created_by = ? WHERE id = ?')
           ->execute([$meetingAt, $minutesText, (int)$user['id'], (int)$existing['id']]);
    } else {
        db()->prepare('INSERT INTO meetings (application_id, scheduled_at, minutes, created_by) VALUES (?, ?, ?, ?)')
           ->execute([$applicationId, $meetingAt, $minutesText, (int)$user['id']]);
    }

    if ($action === 'schedule_meeting') {
        notify_user((int)$application['landlord_id'], $applicationId, 'TWG Meeting Scheduled', 'The TWG meeting for your application has been scheduled.');
        audit_log((int)$user['id'], 'TWG_MEETING_SCHEDULED', 'applications', $applicationId, ['scheduled_at' => $meetingAt]);
        $_SESSION['flash_success'] = 'Meeting schedule saved.';
        redirect('twg/meeting.php?id=' . $applicationId . '#meeting-management');
    }

    if ($action === 'save_minutes') {
        advance_application($applicationId, 'DELIBERATION', 11);
        notify_user((int)$application['landlord_id'], $applicationId, 'TWG Meeting Conducted', 'The TWG meeting for your application has been conducted. Your application is now under deliberation.');
        notify_role(ROLE_ZONING, $applicationId, 'TWG Minutes Ready for Resolution', 'The LZRC TWG minutes of meeting have been generated and are ready for zoning officer review and resolution preparation.');
        audit_log((int)$user['id'], 'TWG_MINUTES_GENERATED_AND_SENT_TO_ZONING', 'applications', $applicationId);
        $_SESSION['flash_success'] = 'Minutes of meeting saved, PDF is ready, and zoning officer has been notified.';
        redirect('twg/meeting.php?id=' . $applicationId . '#minutes-panel');
    }

    $_SESSION['flash_error'] = 'Unknown meeting action.';
    redirect('twg/meeting.php?id=' . $applicationId);
}

// ── Page data ─────────────────────────────────────────────────────────────────
$applications = officer_applications(['FOR_MEETING']);
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$meeting      = $application ? latest_meeting_for_application((int)$application['id']) : null;

if ($application) {
    $existsInList = false;
    foreach ($applications as $row) { if ((int)$row['id'] === (int)$application['id']) { $existsInList = true; break; } }
    if (!$existsInList) array_unshift($applications, $application);
}

$minutesData    = twg_minutes_data_from_meeting($meeting, $application, $user);
$meetingRows    = twg_all_meetings();
$calendarEvents = [];
foreach ($meetingRows as $row) {
    $ts = !empty($row['scheduled_at']) ? strtotime($row['scheduled_at']) : false;
    if (!$ts) continue;
    $rowMinutes = twg_minutes_data_from_meeting($row, null, null);
    $calendarEvents[] = [
        'applicationId' => (int)$row['application_id'],
        'date'          => date('Y-m-d', $ts),
        'time'          => date('H:i', $ts),
        'timeText'      => date('h:i A', $ts),
        'registry'      => $row['registry_number'] ?? '',
        'property'      => $row['property_title'] ?? '',
        'type'          => $rowMinutes['meeting_type'] ?: 'Meeting',
        'label'         => trim(($row['registry_number'] ?? '') . ' - ' . ($row['property_title'] ?? '')),
    ];
}
$upcomingEvents = array_values(array_filter($calendarEvents, fn($e) => strtotime($e['date'] . ' ' . $e['time']) >= strtotime(date('Y-m-d') . ' 00:00:00')));
if (!$upcomingEvents) $upcomingEvents = $calendarEvents;

require __DIR__ . '/../partials/header.php';
?>
<style>
:root{--mm-bg:#0f131c;--mm-card:#151a25;--mm-card-2:#1b2130;--mm-border:#2a3144;--mm-border-2:#353d52;--mm-text:#f8fafc;--mm-muted:#94a3b8;--mm-muted-2:#64748b;--mm-accent:#4fffb0;--mm-accent-2:#00c8ff;--mm-warning:#f59e0b;--mm-green:#22c55e;--mm-blue:#3b82f6;--mm-danger:#ef4444;}
body.meeting-management-page{background:radial-gradient(circle at 1px 1px,rgba(79,255,176,.08) 1px,transparent 0),linear-gradient(180deg,#0d111a 0%,#111827 100%);background-size:30px 30px,100% 100%;color:var(--mm-text);}
.mm-shell{max-width:1180px;margin:0 auto;padding:30px 18px 56px;}
.mm-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;}
.mm-title-block h1{margin:0;color:#fff;font-size:clamp(1.65rem,3vw,2.35rem);font-weight:900;letter-spacing:-.04em;}
.mm-title-block p{margin:8px 0 0;color:var(--mm-muted);font-size:.92rem;line-height:1.6;}
.mm-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.mm-btn,.mm-btn-outline,.mm-btn-accent{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:10px 16px;border-radius:11px;border:1px solid transparent;font-size:.84rem;font-weight:850;text-decoration:none;cursor:pointer;transition:transform .18s,box-shadow .18s,background .18s,border-color .18s;}
.mm-btn-accent{background:var(--mm-accent);color:#04130d;box-shadow:0 12px 26px rgba(79,255,176,.20);}
.mm-btn-accent:hover{color:#04130d;transform:translateY(-1px);box-shadow:0 16px 30px rgba(79,255,176,.26);}
.mm-btn{background:#243047;color:#fff;border-color:var(--mm-border-2);}
.mm-btn:hover{color:#fff;background:#2c3852;transform:translateY(-1px);}
.mm-btn-outline{background:transparent;color:var(--mm-text);border-color:var(--mm-border-2);}
.mm-btn-outline:hover{color:var(--mm-text);background:rgba(255,255,255,.04);transform:translateY(-1px);}
.mm-grid-2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;margin-bottom:22px;}
.mm-card{overflow:hidden;border-radius:16px;background:rgba(21,26,37,.96);border:1px solid var(--mm-border);box-shadow:0 18px 44px rgba(0,0,0,.28);}
.mm-card-header{display:flex;align-items:center;gap:10px;padding:18px 22px;border-bottom:1px solid var(--mm-border);}
.mm-card-icon{width:18px;height:18px;color:var(--mm-accent);flex:0 0 18px;}
.mm-card-title{margin:0;color:#fff;font-size:1rem;font-weight:900;letter-spacing:-.01em;}
.mm-card-sub{margin:4px 0 0;color:var(--mm-muted);font-size:.8rem;line-height:1.5;}
.mm-card-body{padding:22px;}
.mm-form-group{margin-bottom:16px;}
.mm-form-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
.mm-form-label{display:block;margin-bottom:8px;color:var(--mm-muted-2);font-size:.74rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;}
.mm-control{width:100%;min-height:40px;padding:10px 12px;border-radius:9px;border:1px solid var(--mm-border-2);background:#1b2130;color:#fff;font-size:.88rem;font-weight:650;outline:none;transition:border-color .16s,box-shadow .16s;}
.mm-control:focus{border-color:var(--mm-accent);box-shadow:0 0 0 4px rgba(79,255,176,.10);}
textarea.mm-control{min-height:118px;resize:vertical;line-height:1.6;}
.mm-type-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:6px;}
.mm-type-card{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:62px;padding:12px;border-radius:10px;border:2px solid var(--mm-border-2);color:#fff;text-align:center;cursor:pointer;transition:border-color .16s,background .16s,transform .16s;}
.mm-type-card input{position:absolute;opacity:0;pointer-events:none;}
.mm-type-card strong{font-size:.82rem;margin-top:5px;}
.mm-type-card span{color:var(--mm-accent);font-size:1rem;line-height:1;}
.mm-type-card:has(input:checked){background:rgba(79,255,176,.08);border-color:var(--mm-accent);}
.mm-type-card:hover{transform:translateY(-1px);}
.mm-calendar-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;}
.mm-calendar-title{margin:0;color:#fff;font-size:.92rem;font-weight:900;}
.mm-cal-nav{display:flex;gap:8px;}
.mm-cal-btn{width:34px;height:34px;border-radius:9px;border:1px solid var(--mm-border-2);background:transparent;color:#fff;cursor:pointer;font-weight:900;}
.mm-cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;}
.mm-cal-weekday{color:var(--mm-muted-2);text-align:center;font-size:.64rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;padding-bottom:8px;}
.mm-cal-day{min-height:44px;border-radius:9px;padding:6px;color:#fff;font-size:.78rem;font-weight:800;text-align:center;position:relative;}
.mm-cal-day.is-muted{opacity:.28;}
.mm-cal-day.is-today{background:rgba(79,255,176,.20);color:var(--mm-accent);}
.mm-cal-day.has-event::after{content:"";position:absolute;left:50%;bottom:5px;width:5px;height:5px;border-radius:999px;background:var(--mm-accent);transform:translateX(-50%);}
.mm-upcoming-title{margin:20px 0 12px;padding-top:18px;border-top:1px solid var(--mm-border);color:var(--mm-muted-2);font-size:.74rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;}
.mm-upcoming-list{display:grid;gap:10px;}
.mm-upcoming-item{display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;background:#1b2130;border:1px solid var(--mm-border-2);}
.mm-avatar{width:36px;height:36px;flex:0 0 36px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--mm-accent),var(--mm-accent-2));color:#06110d;font-size:.74rem;font-weight:950;}
.mm-upcoming-name{margin:0;color:#fff;font-size:.84rem;font-weight:850;}
.mm-upcoming-meta{margin:2px 0 0;color:var(--mm-muted-2);font-size:.78rem;font-weight:600;}
.mm-badge{margin-left:auto;display:inline-flex;align-items:center;justify-content:center;min-height:24px;padding:5px 9px;border-radius:999px;background:rgba(245,158,11,.14);color:var(--mm-warning);font-size:.68rem;font-weight:900;white-space:nowrap;}
.mm-badge.today{background:rgba(79,255,176,.12);color:var(--mm-accent);}
.mm-panel{overflow:hidden;border-radius:16px;background:rgba(21,26,37,.96);border:1px solid var(--mm-border);box-shadow:0 18px 44px rgba(0,0,0,.28);}
.mm-panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:20px 22px;border-bottom:1px solid var(--mm-border);}
.mm-panel-title{margin:0;color:#fff;font-size:1.05rem;font-weight:900;letter-spacing:-.02em;}
.mm-panel-sub{margin:5px 0 0;color:var(--mm-muted);font-size:.82rem;line-height:1.55;}
.mm-panel-body{padding:22px;}
.mm-section-title{margin:20px 0 14px;color:var(--mm-accent);font-size:.78rem;font-weight:950;text-transform:uppercase;letter-spacing:.09em;}
.mm-section-title:first-child{margin-top:0;}
.mm-warning{display:none;margin-top:12px;padding:11px 13px;border-radius:10px;background:rgba(239,68,68,.12);color:#fecaca;border:1px solid rgba(239,68,68,.28);font-size:.8rem;font-weight:750;}
.mm-warning.is-visible{display:block;}
.mm-empty{padding:42px 18px;text-align:center;color:var(--mm-muted);font-size:.9rem;line-height:1.7;}
.mm-muted-note{color:var(--mm-muted);font-size:.82rem;line-height:1.55;}
@media(max-width:991.98px){.mm-grid-2{grid-template-columns:1fr;}.mm-topbar,.mm-panel-header{align-items:stretch;flex-direction:column;}}
@media(max-width:767.98px){.mm-shell{padding:24px 14px 44px;}.mm-form-row{grid-template-columns:1fr;}.mm-type-grid{grid-template-columns:1fr;}}
</style>
<script>document.body.classList.add('meeting-management-page');</script>

<div class="mm-shell">
<div class="mm-topbar">
  <div class="mm-title-block">
    <h1>Meeting Management</h1>
    <p>Schedule LZRC TWG meetings, track upcoming meeting dates, prepare official minutes, generate a PDF copy, and notify the zoning officer for resolution preparation.</p>
  </div>
  <div class="mm-actions">
    <a class="mm-btn-outline" href="index.php">Back to Dashboard</a>
    <?php if ($application && $meeting): ?>
      <a class="mm-btn-accent" href="meeting.php?id=<?= (int)$application['id'] ?>&download_minutes_pdf=1" target="_blank" rel="noopener">Download Minutes PDF</a>
    <?php endif; ?>
  </div>
</div>

<section class="mm-grid-2" id="meeting-management">
  <!-- Schedule Meeting -->
  <div class="mm-card">
    <div class="mm-card-header">
      <svg class="mm-card-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15 10 4.55-2.28A1 1 0 0 1 21 8.62v6.76a1 1 0 0 1-1.45.9L15 14m-9 3h7a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2Z"/></svg>
      <div><h2 class="mm-card-title">Schedule Meeting</h2><p class="mm-card-sub">Create or update the TWG meeting schedule for an application.</p></div>
    </div>
    <div class="mm-card-body">
      <form method="post" id="meetingScheduleForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="schedule_meeting">
        <div class="mm-form-group">
          <label class="mm-form-label" for="scheduleApplication">Application</label>
          <select class="mm-control" id="scheduleApplication" name="application_id" required>
            <?php if ($applications): ?>
              <?php foreach ($applications as $row): ?>
                <option value="<?= (int)$row['id'] ?>" <?= $application && (int)$application['id'] === (int)$row['id'] ? 'selected' : '' ?>>
                  <?= e($row['registry_number']) ?> — <?= e($row['property_title']) ?>
                </option>
              <?php endforeach; ?>
            <?php else: ?>
              <option value="">No applications ready for meeting</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label">Meeting Type</label>
          <div class="mm-type-grid">
            <label class="mm-type-card"><input type="radio" name="meeting_type" value="Virtual" <?= ($minutesData['meeting_type'] ?? 'Virtual') === 'Virtual' ? 'checked' : '' ?>><span>&#9635;</span><strong>Virtual</strong></label>
            <label class="mm-type-card"><input type="radio" name="meeting_type" value="In-Person" <?= ($minutesData['meeting_type'] ?? '') === 'In-Person' ? 'checked' : '' ?>><span>&#9637;</span><strong>In-Person</strong></label>
          </div>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="scheduleMeetingLink">Video Conference Link / Venue</label>
          <input class="mm-control" id="scheduleMeetingLink" name="meeting_link" value="<?= e($minutesData['meeting_link'] ?? '') ?>" placeholder="https://meet.google.com/xxx or CPDO Conference Room">
        </div>
        <div class="mm-form-row">
          <div class="mm-form-group">
            <label class="mm-form-label" for="scheduleDate">Meeting Date</label>
            <input class="mm-control" id="scheduleDate" type="date" name="meeting_date" value="<?= e($minutesData['meeting_date'] ?? '') ?>" required>
          </div>
          <div class="mm-form-group">
            <label class="mm-form-label" for="scheduleTime">Time Slot</label>
            <input class="mm-control" id="scheduleTime" type="time" name="meeting_time" value="<?= e($minutesData['meeting_time'] ?? '') ?>" step="900" required>
          </div>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="scheduleFacilitator">Meeting Facilitator</label>
          <input class="mm-control" id="scheduleFacilitator" name="facilitator" value="<?= e($minutesData['facilitator'] ?? '') ?>" placeholder="Name of TWG meeting facilitator" required>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="scheduleInvitees">Invitees</label>
          <textarea class="mm-control" id="scheduleInvitees" name="invitees" rows="3" placeholder="List invitees, representatives, offices, or attendees."><?= e($minutesData['invitees'] ?? '') ?></textarea>
        </div>
        <div class="mm-warning" id="scheduleConflictWarning">This date and time already has a scheduled TWG meeting for another application.</div>
        <button class="mm-btn-accent" id="scheduleSubmitBtn" style="width:100%;" <?= $applications ? '' : 'disabled' ?>>Schedule Meeting</button>
      </form>
    </div>
  </div>

  <!-- Meeting Calendar -->
  <div class="mm-card">
    <div class="mm-card-header">
      <svg class="mm-card-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/></svg>
      <div><h2 class="mm-card-title">Meeting Calendar</h2><p class="mm-card-sub">View scheduled TWG meetings and avoid schedule conflicts.</p></div>
    </div>
    <div class="mm-card-body">
      <div class="mm-calendar-header">
        <button class="mm-cal-btn" type="button" id="calendarPrev" aria-label="Previous month">&#8249;</button>
        <h3 class="mm-calendar-title" id="calendarTitle">Calendar</h3>
        <button class="mm-cal-btn" type="button" id="calendarNext" aria-label="Next month">&#8250;</button>
      </div>
      <div class="mm-cal-grid" id="calendarGrid"></div>
      <div class="mm-upcoming-title">Upcoming Meetings</div>
      <?php if ($upcomingEvents): ?>
        <div class="mm-upcoming-list">
          <?php foreach (array_slice($upcomingEvents, 0, 5) as $event): ?>
            <?php $ets = strtotime($event['date'] . ' ' . $event['time']); $isToday = date('Y-m-d') === $event['date']; ?>
            <div class="mm-upcoming-item">
              <div class="mm-avatar"><?= e(twg_initials($event['property'] ?: $event['registry'])) ?></div>
              <div>
                <p class="mm-upcoming-name"><?= e($event['property'] ?: $event['registry']) ?></p>
                <p class="mm-upcoming-meta"><?= $ets ? e(date('M d', $ets)) : e($event['date']) ?> &middot; <?= e($event['timeText']) ?> &middot; <?= e($event['type']) ?></p>
              </div>
              <span class="mm-badge <?= $isToday ? 'today' : '' ?>"><?= $isToday ? 'Today' : 'Upcoming' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="mm-empty">No TWG meetings are scheduled yet.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Minutes of Meeting -->
<section class="mm-panel" id="minutes-panel">
  <div class="mm-panel-header">
    <div>
      <h2 class="mm-panel-title">Minutes of Meeting</h2>
      <p class="mm-panel-sub">Fill out the official meeting minutes. Saving this form generates the minutes content, moves the application to deliberation, and notifies the zoning officer.</p>
    </div>
    <?php if ($application): ?>
      <div class="mm-muted-note"><strong><?= e($application['registry_number']) ?></strong><br><?= e($application['property_title']) ?></div>
    <?php endif; ?>
  </div>
  <div class="mm-panel-body">
    <?php if ($application): ?>
      <form method="post" id="minutesForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
        <input type="hidden" name="action" value="save_minutes">

        <div class="mm-section-title">Meeting Information</div>
        <div class="mm-form-row">
          <div class="mm-form-group">
            <label class="mm-form-label" for="associationName">Name of Association</label>
            <input class="mm-control" id="associationName" name="association_name" value="<?= e($minutesData['association_name'] ?? 'LZRC TWG') ?>" required>
          </div>
          <div class="mm-form-group">
            <label class="mm-form-label" for="momMeetingType">Type of Meeting</label>
            <select class="mm-control" id="momMeetingType" name="meeting_type" required>
              <option value="Virtual" <?= ($minutesData['meeting_type'] ?? '') === 'Virtual' ? 'selected' : '' ?>>Virtual</option>
              <option value="In-Person" <?= ($minutesData['meeting_type'] ?? '') === 'In-Person' ? 'selected' : '' ?>>In-Person</option>
              <option value="Hybrid" <?= ($minutesData['meeting_type'] ?? '') === 'Hybrid' ? 'selected' : '' ?>>Hybrid</option>
              <option value="Special Meeting" <?= ($minutesData['meeting_type'] ?? '') === 'Special Meeting' ? 'selected' : '' ?>>Special Meeting</option>
              <option value="Regular Meeting" <?= ($minutesData['meeting_type'] ?? '') === 'Regular Meeting' ? 'selected' : '' ?>>Regular Meeting</option>
            </select>
          </div>
        </div>
        <div class="mm-form-row">
          <div class="mm-form-group">
            <label class="mm-form-label" for="momDate">Date</label>
            <input class="mm-control" id="momDate" type="date" name="meeting_date" value="<?= e($minutesData['meeting_date'] ?? '') ?>" required>
          </div>
          <div class="mm-form-group">
            <label class="mm-form-label" for="momTime">Time</label>
            <input class="mm-control" id="momTime" type="time" name="meeting_time" value="<?= e($minutesData['meeting_time'] ?? '') ?>" step="900" required>
          </div>
        </div>
        <div class="mm-form-row">
          <div class="mm-form-group">
            <label class="mm-form-label" for="momFacilitator">Meeting Facilitator</label>
            <input class="mm-control" id="momFacilitator" name="facilitator" value="<?= e($minutesData['facilitator'] ?? '') ?>" required>
          </div>
          <div class="mm-form-group">
            <label class="mm-form-label" for="momMeetingLink">Video Conference Link / Venue</label>
            <input class="mm-control" id="momMeetingLink" name="meeting_link" value="<?= e($minutesData['meeting_link'] ?? '') ?>" placeholder="Meeting link or venue">
          </div>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="invitees">Invitees</label>
          <textarea class="mm-control" id="invitees" name="invitees" rows="3" placeholder="List all invitees."><?= e($minutesData['invitees'] ?? '') ?></textarea>
        </div>

        <div class="mm-section-title">Attendance and Opening</div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="callToOrder">Call to Order</label>
          <textarea class="mm-control" id="callToOrder" name="call_to_order" rows="3" placeholder="State who called the meeting to order and the exact time."><?= e($minutesData['call_to_order'] ?? '') ?></textarea>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="rollCall">Roll Call</label>
          <textarea class="mm-control" id="rollCall" name="roll_call" rows="3" placeholder="Record roll call summary."><?= e($minutesData['roll_call'] ?? '') ?></textarea>
        </div>
        <div class="mm-form-row">
          <div class="mm-form-group">
            <label class="mm-form-label" for="attendeesPresent">Attendees Present</label>
            <textarea class="mm-control" id="attendeesPresent" name="attendees_present" rows="5" placeholder="List attendees who were present."><?= e($minutesData['attendees_present'] ?? '') ?></textarea>
          </div>
          <div class="mm-form-group">
            <label class="mm-form-label" for="absent">Absent</label>
            <textarea class="mm-control" id="absent" name="absent" rows="5" placeholder="List members or invitees who were absent."><?= e($minutesData['absent'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="mm-section-title">Meeting Agenda</div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="agendaReports">a. Reports</label>
          <textarea class="mm-control" id="agendaReports" name="agenda_reports" rows="5" placeholder="Summarize reports presented during the meeting."><?= e($minutesData['agenda_reports'] ?? '') ?></textarea>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="openIssues">b. Open Issues</label>
          <textarea class="mm-control" id="openIssues" name="open_issues" rows="5" placeholder="Record unresolved issues, clarifications, or concerns."><?= e($minutesData['open_issues'] ?? '') ?></textarea>
        </div>
        <div class="mm-form-row">
          <div class="mm-form-group">
            <label class="mm-form-label" for="remarks">Remarks</label>
            <textarea class="mm-control" id="remarks" name="remarks" rows="5" placeholder="Enter remarks from the TWG members."><?= e($minutesData['remarks'] ?? '') ?></textarea>
          </div>
          <div class="mm-form-group">
            <label class="mm-form-label" for="response">Response</label>
            <textarea class="mm-control" id="response" name="response" rows="5" placeholder="Enter responses, clarifications, or applicant explanations."><?= e($minutesData['response'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="specialNotes">c. Special Notes</label>
          <textarea class="mm-control" id="specialNotes" name="special_notes" rows="4" placeholder="Record special notes, instructions, or additional observations."><?= e($minutesData['special_notes'] ?? '') ?></textarea>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="adjournment">d. Adjournment</label>
          <textarea class="mm-control" id="adjournment" name="adjournment" rows="3" placeholder="State the adjournment time and closing remarks."><?= e($minutesData['adjournment'] ?? '') ?></textarea>
        </div>

        <div class="mm-section-title">Signatories</div>
        <div class="mm-form-row">
          <div class="mm-form-group">
            <label class="mm-form-label" for="preparedBy">Prepared by</label>
            <input class="mm-control" id="preparedBy" name="prepared_by" value="<?= e($minutesData['prepared_by'] ?? '') ?>" required>
          </div>
          <div class="mm-form-group">
            <label class="mm-form-label" for="approvedBy">Approved</label>
            <input class="mm-control" id="approvedBy" name="approved_by" value="<?= e($minutesData['approved_by'] ?? '') ?>" placeholder="Approving officer / chairperson">
          </div>
        </div>
        <div class="mm-form-group">
          <label class="mm-form-label" for="notedBy">Noted</label>
          <input class="mm-control" id="notedBy" name="noted_by" value="<?= e($minutesData['noted_by'] ?? '') ?>" placeholder="Noted by">
        </div>

        <div class="mm-warning" id="minutesConflictWarning">This date and time already has a scheduled TWG meeting for another application.</div>
        <div class="mm-actions mt-3">
          <button class="mm-btn-accent" id="minutesSubmitBtn" type="submit">Save Minutes, Generate PDF &amp; Notify Zoning Officer</button>
          <?php if ($meeting): ?>
            <a class="mm-btn-outline" href="meeting.php?id=<?= (int)$application['id'] ?>&download_minutes_pdf=1" target="_blank" rel="noopener">Download PDF</a>
          <?php endif; ?>
        </div>
        <p class="mm-muted-note mt-3">The minutes are saved in the existing meeting record. The PDF is generated on demand from the saved minutes, and the zoning officer receives a system notification for resolution preparation.</p>
      </form>
    <?php else: ?>
      <div class="mm-empty">Select an application to schedule a meeting and prepare the minutes of meeting.</div>
    <?php endif; ?>
  </div>
</section>
</div>

<script>
(function () {
    var meetingEvents = <?= json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var calendarGrid  = document.getElementById('calendarGrid');
    var calendarTitle = document.getElementById('calendarTitle');
    var calendarPrev  = document.getElementById('calendarPrev');
    var calendarNext  = document.getElementById('calendarNext');
    var currentDate   = new Date(); currentDate.setDate(1);

    function pad(n) { return String(n).padStart(2, '0'); }
    function dateKey(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function eventsForDate(k) { return meetingEvents.filter(function (e) { return e.date === k; }); }

    function renderCalendar() {
        if (!calendarGrid || !calendarTitle) return;
        calendarGrid.innerHTML = '';
        var y = currentDate.getFullYear(), m = currentDate.getMonth();
        calendarTitle.textContent = currentDate.toLocaleString('default', { month: 'long', year: 'numeric' });
        ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(function (d) {
            var el = document.createElement('div'); el.className = 'mm-cal-weekday'; el.textContent = d; calendarGrid.appendChild(el);
        });
        var first = new Date(y, m, 1), start = new Date(first);
        start.setDate(first.getDate() - first.getDay());
        var todayKey = dateKey(new Date());
        for (var i = 0; i < 42; i++) {
            var day = new Date(start); day.setDate(start.getDate() + i);
            var k = dateKey(day), evs = eventsForDate(k);
            var cell = document.createElement('div'); cell.className = 'mm-cal-day';
            cell.textContent = day.getDate();
            if (day.getMonth() !== m) cell.classList.add('is-muted');
            if (k === todayKey) cell.classList.add('is-today');
            if (evs.length > 0) { cell.classList.add('has-event'); cell.title = evs.map(function (e) { return e.timeText + ' · ' + e.registry + ' · ' + e.property; }).join('\n'); }
            calendarGrid.appendChild(cell);
        }
    }

    if (calendarPrev) calendarPrev.addEventListener('click', function () { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(); });
    if (calendarNext) calendarNext.addEventListener('click', function () { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(); });
    renderCalendar();

    function hasConflict(dateVal, timeVal, appId) {
        if (!dateVal || !timeVal) return false;
        return meetingEvents.some(function (e) { return e.date === dateVal && e.time === timeVal && String(e.applicationId) !== String(appId || '0'); });
    }

    function bindConflictChecker(opts) {
        var dateInput = document.getElementById(opts.dateId);
        var timeInput = document.getElementById(opts.timeId);
        var appInput  = opts.applicationId ? document.getElementById(opts.applicationId) : null;
        var warning   = document.getElementById(opts.warningId);
        var submit    = document.getElementById(opts.submitId);
        if (!dateInput || !timeInput || !warning || !submit) return;
        function getAppId() { return appInput ? appInput.value : <?= $application ? (int)$application['id'] : 0 ?>; }
        function check() {
            var conflict = hasConflict(dateInput.value, timeInput.value, getAppId());
            warning.classList.toggle('is-visible', conflict);
            submit.disabled = conflict;
        }
        dateInput.addEventListener('change', check);
        timeInput.addEventListener('change', check);
        if (appInput) appInput.addEventListener('change', check);
        check();
    }

    bindConflictChecker({ dateId: 'scheduleDate', timeId: 'scheduleTime', applicationId: 'scheduleApplication', warningId: 'scheduleConflictWarning', submitId: 'scheduleSubmitBtn' });
    bindConflictChecker({ dateId: 'momDate', timeId: 'momTime', applicationId: null, warningId: 'minutesConflictWarning', submitId: 'minutesSubmitBtn' });

    // Sync schedule form → minutes form
    function syncValue(srcId, tgtId) {
        var src = document.getElementById(srcId), tgt = document.getElementById(tgtId);
        if (!src || !tgt) return;
        function sync() { if (!tgt.value) tgt.value = src.value; }
        src.addEventListener('change', sync); src.addEventListener('input', sync);
    }
    syncValue('scheduleDate', 'momDate');
    syncValue('scheduleTime', 'momTime');
    syncValue('scheduleFacilitator', 'momFacilitator');
    syncValue('scheduleMeetingLink', 'momMeetingLink');
    syncValue('scheduleInvitees', 'invitees');
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
