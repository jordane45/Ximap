<?php

//-------------------------------------------------------------//
//affichage des erreurs PHP
//-------------------------------------------------------------//
error_reporting(E_ALL);
ini_set('display_errors', TRUE);
ini_set('display_startup_errors', TRUE);

//paramètres
//Connection Details à BOITE MAIL OVH
$server = '{ssl0.ovh.net:993/imap/ssl}';
$mailBox = 'INBOX.INBOX.Saved'; // ici, on travaille dans le sous-dossier "Saved" //   par défaut, 'INBOX' est le premier niveau (au moins chez OVH );
$username = "adresseMail";
$password = "password";
//Dossier où déplacer les mails traités 



require_once 'Ximap.php';

// Exemple suppression d'anciens mails
try {
  $oM = new Ximap($server, $mailBox, $username, $password);
  /*
    $lst = $oM->getMailBoxes();
    var_dump($lst);
   */

  //on recherche tous les mails qui ont plus de 14 jours pour les supprimer
  $searchArray = ['BEFORE' => date('j F Y', strtotime('14 day ago'))];
  $arrMails = $oM->getMails($searchArray);
  if (!empty($arrMails['EMAILS'])) {
    echo "<pre> Suppression des mails : <br>" . PHP_EOL;
    foreach ($arrMails['EMAILS'] as $M) {
      print_r($M);
      $oM->deleteMail($M['NUM']);
    }
    echo "</pre>" . PHP_EOL;
    $oM->expunge();
  } else {
    echo "Aucun mail à supprimer " . PHP_EOL;
  }
} catch (Exception $ex) {
  echo "Erreur : " . $e->getMessage();
  exit;
}
