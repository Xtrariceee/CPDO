<?php
/**
 * Admin Officer — Review & Approve Clearance Requests
 */
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);
verify_csrf();

/* ── POST: approve or reject ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes  = trim($_POST['officer_notes'] ?? '');

    if (!$id || !in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['flash_error'] = 'Invalid action.';
        redirect('admin-officer/clearance-review.php');
    }

    $reqStmt = db()->prepare('SELECT * FROM clearance_requests WHERE id = ?');
    $reqStmt->execute([$id]);
    $req = $reqStmt->fetch();
    if (!$req) { $_SESSION['flash_error'] = 'Request not found.'; redirect('admin-officer/clearance-review.php'); }

    $newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
    db()->prepare(
        'UPDATE clearance_requests
         SET status=?, officer_notes=?, reviewed_by=?, reviewed_at=NOW()
         WHERE id=?'
    )->execute([$newStatus, $notes ?: null, (int)$user['id'], $id]);

    /* generate and persist the certificate when approving */
    if ($action === 'approve') {
        try {
            /* inline minimal PDF builder so we don't stream to the browser */
            $landlordStmt = db()->prepare(
                'SELECT CONCAT_WS(" ", first_name, middle_name, last_name) AS full_name FROM users WHERE id = ?'
            );
            $landlordStmt->execute([(int)$req['landlord_id']]);
            $landlordRow = $landlordStmt->fetch();
            $landlordName = $landlordRow['full_name'] ?? 'Landlord';

            $pageW = 842; $pageH = 595;
            $certNo = 'CERT-' . strtoupper(bin2hex(random_bytes(4)));
            $now    = date('F j, Y');

            function _ao_cert_escape(string $s): string {
                return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
            }

            $lines = [
                [0, $pageH-80,  2, 22, 'CITY PLANNING AND DEVELOPMENT OFFICE'],
                [0, $pageH-104, 1, 13, 'Official Clearance & Certification Portal'],
                [0, $pageH-160, 2, 28, 'CERTIFICATE OF COMPLIANCE'],
                [0, $pageH-200, 1, 12, 'This is to certify that'],
                [0, $pageH-228, 2, 18, strtoupper($landlordName)],
                [0, $pageH-255, 1, 12, 'has been duly verified and approved for the following clearance / certification:'],
                [0, $pageH-290, 2, 16, $req['title']],
                [0, $pageH-316, 1, 11, 'Issuing Office: ' . $req['office']],
                [0, $pageH-352, 1, 11, 'Certificate No.: ' . $certNo],
                [0, $pageH-370, 1, 11, 'Date of Issue:   ' . $now],
                [0, $pageH-388, 1, 11, 'Valid for one (1) year from date of issue unless otherwise revoked.'],
                [0, $pageH-440, 1, 10, 'This certificate is generated electronically by the CPDO Land Reclassification Portal.'],
                [0, $pageH-456, 1, 10, 'Authenticity may be verified through the portal reference number above.'],
            ];

            $objects = [
                1 => '<< /Type /Catalog /Pages 2 0 R >>',
                2 => '',
                3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
                4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            ];

            $border =
                "0.96 0.85 0.35 RG\n3 w\n20 20 " . ($pageW-40) . " " . ($pageH-40) . " re S\n" .
                "1.5 w\n30 30 " . ($pageW-60) . " " . ($pageH-60) . " re S\n" .
                "0.09 0.17 0.29 rg\n0 " . ($pageH-110) . " {$pageW} 110 re f\n";
            $b = count($objects)+1;
            $objects[$b] = '<< /Length '.strlen($border)." >>\nstream\n".$border."endstream";

            $tc = "BT\n";
            foreach ($lines as $l) {
                [$x,$y,$fn,$sz,$txt] = $l;
                $aw  = strlen($txt)*$sz*0.55;
                $cx  = max(40, ($pageW-$aw)/2);
                $col = ($y > $pageH-130) ? "1 1 1 rg\n" : "0.09 0.17 0.29 rg\n";
                $tc .= $col . "/F{$fn} {$sz} Tf\n{$cx} {$y} Td\n(_ao_cert_escape_PLACEHOLDER) Tj\n0 0 Td\n";
                $tc  = str_replace('_ao_cert_escape_PLACEHOLDER', _ao_cert_escape($txt), $tc);
            }
            $tc .= "ET\n";
            $t = count($objects)+1;
            $objects[$t] = '<< /Length '.strlen($tc)." >>\nstream\n".$tc."endstream";

            $p = count($objects)+1;
            $objects[$p] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageW} {$pageH}] " .
                           "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents [{$b} 0 R {$t} 0 R] >>";
            $objects[2] = "<< /Type /Pages /Kids [{$p} 0 R] /Count 1 >>";

            $pdf = "%PDF-1.4\n"; $offs=[]; $n=count($objects);
            for ($i=1;$i<=$n;$i++){$offs[$i]=strlen($pdf);$pdf.="{$i} 0 obj\n{$objects[$i]}\nendobj\n";}
            $xref=strlen($pdf);
            $pdf.="xref\n0 ".($n+1)."\n0000000000 65535 f \n";
            for($i=1;$i<=$n;$i++){$pdf.=sprintf("%010d 00000 n \n",$offs[$i]);}
            $pdf.="trailer\n<< /Size ".($n+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

            db()->prepare('UPDATE clearance_requests SET certificate_data=? WHERE id=?')
               ->execute([$pdf, $id]);
        } catch (Throwable $certErr) {
            /* non-fatal: landlord can re-download later */
            audit_log((int)$user['id'], 'CLEARANCE_CERT_GEN_FAILED', 'clearance_requests', $id,
                      ['error' => $certErr->getMessage()]);
        }
    }

    $title = $action === 'approve'
        ? 'Clearance Approved: ' . $req['title']
        : 'Clearance Rejected: ' . $req['title'];
    $msg = $action === 'approve'
        ? 'Your ' . $req['title'] . ' has been approved. Log in to download your official certificate.'
        : 'Your ' . $req['title'] . ' was not approved.' . ($notes ? ' Officer note: ' . $notes : '');

    notify_user((int)$req['landlord_id'], null, $title, $msg);
    audit_log((int)$user['id'], 'CLEARANCE_' . strtoupper($action) . 'D', 'clearance_requests', $id, ['notes' => $notes]);

    $_SESSION['flash_success'] = 'Request ' . ($action === 'approve' ? 'approved' : 'rejected') . '.';
    redirect('admin-officer/clearance-review.php');
}

/* ── page data ─────────────────────────────────────────────────────────────  */
$filter = $_GET['filter'] ?? 'PENDING';
$allowed = ['PENDING', 'APPROVED', 'REJECTED', 'ALL'];
if (!in_array($filter, $allowed, true)) $filter = 'PENDING';

$sql = 'SELECT cr.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name,
               u.email AS landlord_email
        FROM clearance_requests cr
        JOIN users u ON u.id = cr.landlord_id';
$params = [];
if ($filter !== 'ALL') {
    $sql .= ' WHERE cr.status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY cr.created_at DESC';
$requests = db()->prepare($sql);
$requests->execute($params);
$requests = $requests->fetchAll();

/* counts */
$counts = db()->query(
    'SELECT status, COUNT(*) AS n FROM clearance_requests GROUP BY status'
)->fetchAll(PDO::FETCH_KEY_PAIR);
$countPending  = (int)($counts['PENDING']  ?? 0);
$countApproved = (int)($counts['APPROVED'] ?? 0);
$countRejected = (int)($counts['REJECTED'] ?? 0);

require __DIR__ . '/../partials/header.php';
?>
<style>
.clrv-shell{max-width:1100px;margin:0 auto;padding:28px 18px 56px;}
.clrv-top{display:flex;align-items:center;gap:14px;margin-bottom:22px;flex-wrap:wrap;}
.clrv-filter-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
.clrv-filter-btn{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:6px 14px;
    border-radius:999px;border:1.5px solid var(--cpdo-border);background:#fff;
    color:var(--cpdo-muted);font-size:var(--text-xs);font-weight:800;cursor:pointer;text-decoration:none;
    transition:background var(--transition-base),border-color var(--transition-base),color var(--transition-base);}
.clrv-filter-btn:hover,.clrv-filter-btn.active{background:var(--cpdo-light);border-color:var(--cpdo-blue);color:var(--cpdo-blue);}
.clrv-cnt{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;
    padding:0 5px;border-radius:999px;background:var(--cpdo-blue);color:#fff;font-size:10px;font-weight:900;}
.clrv-card{background:#fff;border:1px solid var(--cpdo-border);border-radius:var(--radius-lg);
    box-shadow:var(--shadow-sm);margin-bottom:14px;overflow:hidden;}
.clrv-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;
    padding:16px 20px 12px;border-bottom:1px solid var(--cpdo-border);flex-wrap:wrap;}
.clrv-title{font-size:var(--text-base);font-weight:800;color:var(--cpdo-deep);margin:0 0 3px;}
.clrv-meta{font-size:var(--text-xs);color:var(--cpdo-muted);}
.clrv-body{padding:14px 20px;}
.clrv-row{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;}
.clrv-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;}
.clrv-notes-input{width:100%;min-height:64px;padding:9px 12px;border-radius:8px;
    border:1.5px solid var(--cpdo-border);font-size:var(--text-sm);resize:vertical;
    transition:border-color var(--transition-base);}
.clrv-notes-input:focus{border-color:var(--cpdo-blue);outline:none;box-shadow:0 0 0 3px rgba(47,128,199,.15);}
.clrv-empty{padding:48px 24px;text-align:center;color:var(--cpdo-muted);font-size:var(--text-sm);}
</style>

<div class="clrv-shell">
    <div class="clrv-top">
        <a class="btn btn-back btn-sm" href="dashboard/index.php">&#8592; Dashboard</a>
        <div>
            <p class="eyebrow mb-0">Administrative Officer</p>
            <h1 class="h3 mb-0">Clearance &amp; Certification Requests</h1>
        </div>
    </div>

    <!-- filter bar -->
    <div class="clrv-filter-bar">
        <?php foreach (['PENDING'=>$countPending,'APPROVED'=>$countApproved,'REJECTED'=>$countRejected,'ALL'=>$countPending+$countApproved+$countRejected] as $f=>$n): ?>
            <a class="clrv-filter-btn <?= $filter===$f?'active':'' ?>"
               href="?filter=<?= $f ?>">
                <?= ucfirst(strtolower($f)) ?>
                <span class="clrv-cnt"><?= $n ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$requests): ?>
        <div class="clrv-empty gov-card">No requests found for this filter.</div>
    <?php endif; ?>

    <?php foreach ($requests as $req):
        $statusClass = match ($req['status']) {
            'APPROVED' => 'status-approved',
            'REJECTED' => 'status-rejected',
            default    => 'status-for_payment',
        };
        $hasCert = !empty($req['certificate_data']);
    ?>
    <div class="clrv-card">
        <div class="clrv-card-head">
            <div>
                <p class="clrv-title"><?= e($req['title']) ?></p>
                <p class="clrv-meta">
                    <?= e($req['office']) ?> &middot;
                    <strong><?= e($req['landlord_name']) ?></strong> (<?= e($req['landlord_email']) ?>) &middot;
                    Submitted <?= e(date('M j, Y g:i A', strtotime($req['created_at']))) ?>
                    <?php if ($req['reviewed_at']): ?>
                        &middot; Reviewed <?= e(date('M j, Y', strtotime($req['reviewed_at']))) ?>
                    <?php endif; ?>
                </p>
            </div>
            <span class="status-pill <?= $statusClass ?>">
                <?= e($req['status']) ?>
            </span>
        </div>

        <div class="clrv-body">
            <div class="clrv-row">
                <!-- view submitted doc -->
                <a class="btn btn-outline-primary btn-sm"
                   href="<?= e(rtrim($config['app']['base_url'],'/')) ?>/landlord/clearance-doc-preview.php?id=<?= (int)$req['id'] ?>"
                   target="_blank">
                    View Submitted Document
                </a>

                <?php if ($req['status'] === 'APPROVED' && $hasCert): ?>
                    <a class="btn btn-success btn-sm"
                       href="<?= e(rtrim($config['app']['base_url'],'/')) ?>/landlord/clearance-certificate.php?id=<?= (int)$req['id'] ?>"
                       target="_blank">
                        View Certificate
                    </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($req['officer_notes'])): ?>
                <p class="mt-2 small text-secondary">
                    <strong>Previous note:</strong> <?= e($req['officer_notes']) ?>
                </p>
            <?php endif; ?>

            <?php if ($req['status'] === 'PENDING'): ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="csrf_token"  value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="request_id"  value="<?= (int)$req['id'] ?>">
                <div class="mb-2">
                    <label class="form-label small fw-bold">Officer Notes (optional)</label>
                    <textarea class="clrv-notes-input" name="officer_notes"
                              placeholder="Add remarks for the landlord…"></textarea>
                </div>
                <div class="clrv-actions">
                    <button type="submit" name="action" value="approve"
                            class="btn btn-success btn-sm"
                            onclick="return confirm('Approve this clearance request and generate certificate?')">
                        &#10003; Approve &amp; Generate Certificate
                    </button>
                    <button type="submit" name="action" value="reject"
                            class="btn btn-danger btn-sm"
                            onclick="return confirm('Reject this request?')">
                        &#10005; Reject
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
