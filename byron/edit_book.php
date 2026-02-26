<?php
/**
 * edit_book.php — Επεξεργασία υπάρχοντος βιβλίου
 *
 * Αυτό το αρχείο δεν έχει δική του λογική ή φόρμα.
 * Λειτουργεί αποκλειστικά ως redirect proxy προς το add_book.php,
 * το οποίο χειρίζεται και τη δημιουργία ΚΑΙ την επεξεργασία βιβλίων
 * ανάλογα με το αν υπάρχει το query param ?id= ή όχι.
 *
 * Αυτή η προσέγγιση (single form, dual mode) αποφεύγει τον διπλασιασμό
 * κώδικα μεταξύ "δημιουργία" και "επεξεργασία".
 *
 * Ροή:
 *   /edit_book.php?id=42
 *        /add_book.php?id=42  (edit mode — φορτώνει τα δεδομένα του βιβλίου 42)
 *
 *   /edit_book.php (χωρίς id)
 *        /catalog.php  (δεν υπάρχει τι να επεξεργαστούμε — πίσω στον κατάλογο)
 */

require 'config.php';
requireEmployee(); // Μόνο συνδεδεμένοι υπάλληλοι μπορούν να επεξεργαστούν βιβλία

// Αν δεν δοθεί id, δεν ξέρουμε ποιο βιβλίο να επεξεργαστούμε — redirect στον κατάλογο
if (!isset($_GET['id'])) {
    header('Location: catalog.php');
    exit;
}

// Cast σε int για να αποτραπεί SQL injection / παραβίαση μέσω του URL param
// π.χ. ?id=1;DROP TABLE books → γίνεται ?id=1
header('Location: add_book.php?id=' . (int)$_GET['id']);
exit;