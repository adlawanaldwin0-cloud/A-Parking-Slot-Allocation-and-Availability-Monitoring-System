<?php
require 'db.php';
session_start();
$staff = in_array($_SESSION['role'] ?? '', ['admin', 'attendant']);
$admin = ($_SESSION['role'] ?? '') === 'admin';
header('Content-Type: application/json');

function out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

$action = $_GET['action'] ?? '';
try {
    // List all slots with the vehicle currently in each
    if ($action === 'slots') {
        $rows = $pdo->query(
            "SELECT s.id, s.code, s.type, s.status, z.name AS zone, p.plate, p.time_in
             FROM slots s JOIN zones z ON z.id = s.zone_id
             LEFT JOIN parking_sessions p ON p.slot_id = s.id AND p.time_out IS NULL
             ORDER BY s.id"
        )->fetchAll();
        if (!$staff) foreach ($rows as &$r) $r['plate'] = null;   // hide plates from the public
        unset($r);
        out($rows);
    }
    // REPORTS (admin only)
    if ($action === 'report') {
        if (!$admin) out(['error' => 'Admin only.'], 403);
        out([
            'totals'  => $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(fee),0) revenue,
                            COALESCE(AVG(TIMESTAMPDIFF(MINUTE,time_in,time_out)),0) avg_mins
                            FROM parking_sessions WHERE time_out IS NOT NULL")->fetch(),
            'hours'   => $pdo->query("SELECT HOUR(time_in) h, COUNT(*) c FROM parking_sessions GROUP BY h ORDER BY h")->fetchAll(),
            'revenue' => $pdo->query("SELECT DATE(time_out) d, SUM(fee) r FROM parking_sessions
                            WHERE time_out >= CURDATE() - INTERVAL 6 DAY GROUP BY d ORDER BY d")->fetchAll(),
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Invalid request.'], 405);
    if (!$staff) out(['error' => 'Please log in as staff.'], 403);

    // ADMIN: manage slots
    if (in_array($action, ['add_slot', 'edit_slot', 'toggle_maintenance'])) {
        if (!$admin) out(['error' => 'Admin only.'], 403);
        $types = ['regular', 'pwd', 'motorcycle'];
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $type = $_POST['type'] ?? '';

        if ($action === 'add_slot') {
            $zone = strtoupper(trim($_POST['zone'] ?? ''));
            if (!preg_match('/^[A-Z]{1,3}$/', $zone) || !preg_match('/^[A-Z0-9]{2,6}$/', $code) || !in_array($type, $types))
                out(['error' => 'Invalid zone, code, or type.'], 422);
            $dup = $pdo->prepare("SELECT 1 FROM slots WHERE code = ?"); $dup->execute([$code]);
            if ($dup->fetch()) out(['error' => "Slot $code already exists."], 409);
            $pdo->prepare("INSERT IGNORE INTO zones (name) VALUES (?)")->execute([$zone]);
            $z = $pdo->prepare("SELECT id FROM zones WHERE name = ?"); $z->execute([$zone]);
            $pdo->prepare("INSERT INTO slots (zone_id, code, type) VALUES (?, ?, ?)")->execute([$z->fetchColumn(), $code, $type]);
            out(['ok' => true, 'message' => "Slot $code added to zone $zone."]);
        }

        $id = (int)($_POST['slot_id'] ?? 0);
        $q = $pdo->prepare("SELECT status FROM slots WHERE id = ? FOR UPDATE"); 
        $pdo->beginTransaction(); $q->execute([$id]); $cur = $q->fetchColumn();
        if (!$cur) { $pdo->rollBack(); out(['error' => 'Slot not found.'], 404); }
        if ($cur === 'occupied') { $pdo->rollBack(); out(['error' => 'Slot is occupied. Release the vehicle first.'], 409); }

        if ($action === 'edit_slot') {
            if (!preg_match('/^[A-Z0-9]{2,6}$/', $code) || !in_array($type, $types)) { $pdo->rollBack(); out(['error' => 'Invalid code or type.'], 422); }
            $dup = $pdo->prepare("SELECT 1 FROM slots WHERE code = ? AND id <> ?"); $dup->execute([$code, $id]);
            if ($dup->fetch()) { $pdo->rollBack(); out(['error' => "Slot $code already exists."], 409); }
            $pdo->prepare("UPDATE slots SET code = ?, type = ? WHERE id = ?")->execute([$code, $type, $id]);
            $pdo->commit(); out(['ok' => true, 'message' => 'Slot updated.']);
        }
        $new = $cur === 'maintenance' ? 'available' : 'maintenance';
        $pdo->prepare("UPDATE slots SET status = ? WHERE id = ?")->execute([$new, $id]);
        $pdo->commit();
        out(['ok' => true, 'message' => $new === 'maintenance' ? 'Slot is now under maintenance.' : 'Slot is back in service.']);
    }

    // ALLOCATE: validate, then assign the nearest free slot that fits the vehicle
    if ($action === 'park') {
        $plate = strtoupper(trim($_POST['plate'] ?? ''));
        $type  = $_POST['vehicle_type'] ?? '';
        if (!preg_match('/^[A-Z0-9 -]{5,8}$/', $plate)) out(['error' => 'Invalid plate number (5-8 letters/numbers).'], 422);
        $prefs = ['car' => ['regular'], 'motorcycle' => ['motorcycle'], 'pwd' => ['pwd', 'regular']];
        if (!isset($prefs[$type])) out(['error' => 'Invalid vehicle type.'], 422);

        $pdo->beginTransaction();
        $dup = $pdo->prepare("SELECT 1 FROM parking_sessions WHERE plate = ? AND time_out IS NULL FOR UPDATE");
        $dup->execute([$plate]);
        if ($dup->fetch()) { $pdo->rollBack(); out(['error' => "$plate is already parked."], 409); }

        foreach ($prefs[$type] as $slotType) {
            // FOR UPDATE locks the row so two users can never get the same slot
            $q = $pdo->prepare("SELECT id, code FROM slots WHERE status = 'available' AND type = ? ORDER BY id LIMIT 1 FOR UPDATE");
            $q->execute([$slotType]);
            if ($slot = $q->fetch()) {
                $pdo->prepare("UPDATE slots SET status = 'occupied' WHERE id = ?")->execute([$slot['id']]);
                $pdo->prepare("INSERT INTO parking_sessions (slot_id, plate, vehicle_type) VALUES (?, ?, ?)")
                    ->execute([$slot['id'], $plate, $type]);
                $pdo->commit();
                out(['ok' => true, 'message' => "$plate assigned to slot {$slot['code']}."]);
            }
        }
        $pdo->rollBack();
        out(['error' => 'No available slot for this vehicle type.'], 409);
    }

    // RELEASE: close the session and compute the fee (P20 first hour, P10 each extra hour)
    if ($action === 'release') {
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT id, plate, TIMESTAMPDIFF(MINUTE, time_in, NOW()) AS mins
                            FROM parking_sessions WHERE slot_id = ? AND time_out IS NULL FOR UPDATE");
        $q->execute([(int)($_POST['slot_id'] ?? 0)]);
        $s = $q->fetch();
        if (!$s) { $pdo->rollBack(); out(['error' => 'That slot is not occupied.'], 409); }

        $hours = max(1, (int)ceil($s['mins'] / 60));
        $fee = 20 + ($hours - 1) * 10;
        $pdo->prepare("UPDATE parking_sessions SET time_out = NOW(), fee = ? WHERE id = ?")->execute([$fee, $s['id']]);
        $pdo->prepare("UPDATE slots SET status = 'available' WHERE id = ?")->execute([(int)$_POST['slot_id']]);
        $pdo->commit();
        out(['ok' => true, 'message' => "{$s['plate']} exited after $hours hour(s). Fee: ₱" . number_format($fee, 2)]);
    }
    out(['error' => 'Unknown action.'], 400);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    out(['error' => 'Server error.'], 500);
}
