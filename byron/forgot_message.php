<?php
/**
 * forgot_password.php — Αίτημα ανάκτησης κωδικού (AJAX endpoint)
 *
 * Δέχεται POST request από τη φόρμα "Ξέχασα τον κωδικό μου" και δημιουργεί
 * ένα εσωτερικό μήνυμα (messages) προς τον admin, ώστε να επαναφέρει χειροκίνητα
 * τον κωδικό του χρήστη μέσω του πίνακα διαχείρισης.
 *
 * ΔΕΝ στέλνει email και ΔΕΝ κάνει αυτόματη επαναφορά κωδικού.
 * Η ροή είναι εσκεμμένα manual: admin βλέπει το μήνυμα → επικοινωνεί → επαναφέρει.
 *
 * Αρχιτεκτονική παρατήρηση — from_user = admin (self-message):
 *   Το σύστημα μηνυμάτων απαιτεί έγκυρο FK στο from_user (δεν υπάρχει system user ID 0).
 *   Λύση: ο admin εμφανίζεται και ως αποστολέας, αλλά το body περιέχει
 *   όλες τις πληροφορίες για τον πραγματικό αιτούντα.
 */

require 'config.php';
header('Content-Type: application/json'); // Όλες οι απαντήσεις είναι JSON

// Απόρριψη μη-POST requests — αυτό το endpoint δεν εξυπηρετεί GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]); exit;
}

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email']    ?? ''); // Προαιρετικό — ο χρήστης μπορεί να μην το δώσει

// Το username είναι το μόνο υποχρεωτικό πεδίο — χωρίς αυτό ο admin δεν ξέρει ποιον να αναζητήσει
if (!$username) {
    echo json_encode(['ok' => false, 'msg' => 'Κενό username']); exit;
}

try {
    $db = getDB();

    // Βρίσκουμε τον πρώτο ενεργό admin (με βάση το παλαιότερο ID) για να του στείλουμε το μήνυμα.
    // Αν δεν υπάρχει κανένας ενεργός admin, το αίτημα δεν μπορεί να προχωρήσει.
    $adminStmt = $db->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id ASC LIMIT 1");
    $admin = $adminStmt->fetch();
    if (!$admin) {
        echo json_encode(['ok' => false, 'msg' => 'Δεν βρέθηκε admin']); exit;
    }

    // Σύνθεση του μηνύματος που θα εμφανιστεί στα εισερχόμενα του admin.
    // Συμπεριλαμβάνουμε IP και timestamp για λόγους ασφάλειας και ιχνηλασιμότητας
    // (π.χ. για να εντοπιστούν κακόβουλες επαναλαμβανόμενες αιτήσεις).
    $subject = "🔑 Αίτημα Ανάκτησης Κωδικού — {$username}";
    $body    = "Ένας χρήστης ζήτησε επαναφορά κωδικού πρόσβασης.\n\n"
             . "Username: {$username}\n"
             . ($email ? "Email επικοινωνίας: {$email}\n" : "Email: δεν δόθηκε\n")
             . "\nΗμερομηνία: " . date('d/m/Y H:i') . "\n"
             . "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'άγνωστη') . "\n\n"
             . "Παρακαλώ επικοινωνήστε με τον χρήστη και επαναφέρετε τον κωδικό του μέσω του πίνακα διαχείρισης.";

    // Εισαγωγή μηνύματος με from_user = to_user = admin ID.
    // Βλ. docblock παραπάνω για την αιτιολόγηση του self-message pattern.
    $db->prepare("INSERT INTO messages (from_user, to_user, subject, body) VALUES (?,?,?,?)")
       ->execute([$admin['id'], $admin['id'], $subject, $body]);

    // Καταγραφή στο audit log — χρήσιμο για να εντοπιστεί κατάχρηση του endpoint
    // (π.χ. πολλαπλά αιτήματα για το ίδιο username σε σύντομο χρονικό διάστημα)
    $db->prepare("INSERT INTO audit_log (user_id, action, target_type, details) VALUES (?,?,?,?)")
       ->execute([$admin['id'], 'forgot_password_request', 'user', "Username: {$username}"]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    // Επιστρέφουμε το μήνυμα εξαίρεσης μόνο σε development.
    // Σε production εξετάστε να επιστρέφετε generic μήνυμα για να μην εκτίθενται
    // εσωτερικές λεπτομέρειες της βάσης σε πιθανό επιτιθέμενο.
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}