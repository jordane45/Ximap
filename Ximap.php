<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Ximap
 *
 * @author jreynet
 */
class Ximap {

  /**
   *  The imap stream  
   *  @var IMAP stream | false
   */
  private $imap;
  private $server;
  private $mbox;

  /**
   *  The identifier of the email targeted  
   *  @var int
   */
  private $emailNumber;

  /**
   *  Attachments array  
   *  @var array
   */
  private $attachments;
  private $overview;

  /**
   *  Message structure object  
   *  @var object
   */
  private $structure;

  /**
   *  The 'save to' path  
   *  @var string
   */
  private $path;

  /**
   *  An array of zip files and their locations ($path)  
   *  @var array
   */
  private $zips;

  /**
   *  Create the IMAP stream
   *  @param string $hostname the 'mailbox'
   *  @param string $username the user's email address
   *  @param string $password the user's password for the account
   */
  public function __construct($server, $mailBox, $username, $password) {
    $this->server = $server;
    $this->mbox = $mailBox;
    $hostname = $server . $mailBox;

    $this->connect($hostname, $username, $password);
  }

  /**
   * 
   * @param type $hostname
   * @param type $username
   * @param type $password
   */
  private function connect($hostname, $username, $password) {
    $this->imap = imap_open($hostname, $username, $password) or die('Cannot connect to mail: ' . imap_last_error());
  }

  /**
   * 
   */
  public function close() {
    imap_close($this->imap);
  }

  /**
   * 
   * @param type $searchArray
   * @return type
   */
  public function create_search_string($searchArray) {
    $items = array();
    foreach ($searchArray as $key => $value) {
      $items[] = trim(strtoupper($key)) . ' "' . $value . '"';
    }
    return implode(" ", $items);
  }

  /**
   * Retourne la liste des dossiers dans la boite mail
   */
  public function getMailBoxes() {
    $list = imap_getmailboxes($this->imap, $this->server . $this->mbox, "*");
    if (is_array($list)) {
      foreach ($list as $key => $val) {
        echo "($key) ";
        echo utf8_encode(imap_utf7_decode($val->name)) . ",";
        echo "'" . $val->delimiter . "',";
        echo $val->attributes . "<br />\n";
      }
    } else {
      echo "<br>imap_getmailboxes a échoué : " . imap_last_error() . PHP_EOL;
    }
  }

  /**
   * 
   * @param string $mboxToGo : sous la forme "INBOX.Sent"
   * @return boolean
   */
  public function gotoOtherMailBox($mboxToGo) {
    imap_reopen($this->imap, $this->server . $mboxToGo) or die(implode(", ", imap_errors()));
  }

  /**
   * 
   */
  public function getMails($searchArray = []) {
    $result = [];
    $searchString = $this->create_search_string($searchArray);
    $result['MAILBOX'] = (array) imap_check($this->imap);
    $result['SEARCHSTRING'] = $searchString;
    $emails = imap_search($this->imap, $searchString);
    if (!empty($emails)) {
      foreach ($emails as $MN) {
        $overview = imap_fetch_overview($this->imap, $MN, 0);
        //$structure = imap_fetchstructure($this->imap, $MN);
        $result['EMAILS'][$MN] = ["NUM" => $MN, 'subject' => $overview[0]->subject, 'date' => $overview[0]->date];
      }
    }

    return $result;
  }

  public function deleteMail($msg_number = 0) {
    if (!empty($msg_number)) {
      imap_delete($this->imap, $msg_number);
    }
  }

  /**
   * Efface tous les messages marqués pour l'effacement par imap_delete(), imap_mail_move(), ou imap_setflag_full().
   */
  public function expunge() {
    imap_expunge($this->imap);
  }

  /**
   *  create files from attachments in a specified directory
   *  @param array $searchArray an array of keyed parameters
   *  @param string $saveToPath path of where to create files [must end with a /]
   */
  public function get_files($searchArray, $saveToPath = NULL) {
    $this->path = $saveToPath;

    $searchString = $this->create_search_string($searchArray);

    if ($emails = imap_search($this->imap, $searchString)) {
      $this->emailNumber = end($emails);

      $overview = imap_fetch_overview($this->imap, $this->emailNumber, 0);
      $this->overview = $overview;
      $this->structure = imap_fetchstructure($this->imap, $this->emailNumber);

      $this->attachments = array();

      if (isset($this->structure->parts) && count($this->structure->parts)) {
        for ($i = 0; $i < count($this->structure->parts); $i++) {
          $this->create_new_array($i);

          if ($this->structure->parts[$i]->ifdparameters) {
            $this->check_ifdparams($i);
          }

          if ($this->structure->parts[$i]->ifparameters) {
            $this->check_ifparams($i);
          }

          if ($this->attachments[$i]['is_attachment']) {
            $this->get_file_content($i);
          }
        }

        foreach ($this->attachments as $attachment) {
          if ($attachment['is_attachment'] == 1) {
            $this->make_file($attachment);
          }
        }
      }
    } else {
      $this->emailNumber = null;
    }
    // imap_close($this->imap);
  }

  /**
   *  extract any files in a zip archive to a specified location
   *  @param string $unzipDest the path for the extraction [must end with a /]
   */
  public function extract_zip_to($unzipDest = NULL) {
    $zip = new ZipArchive;
    foreach ($this->zips as $zipfile) {
      $res = $zip->open($zipfile);
      if ($res === TRUE) {
        $zip->extractTo($unzipDest);
        $zip->close();
      }
    }
  }

  /**
   *  creates a file from an attachment and stores path for any zip files
   *  @param array $attachment holds all the info for the attachment
   */
  private function make_file($attachment) {
    $filename = str_replace(["=?iso-8859-1?Q?", "?="], ["", ""], $attachment['name']);
    if (empty($filename))
      $filename = str_replace(["=?iso-8859-1?Q?", "?="], ["", ""], $attachment['filename']);
    if (empty($filename))
      $filename = time() . ".dat";
    $loc = $this->path . $filename;
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) == 'zip')
      $this->zips[] = $loc;
    $fp = fopen($loc, "w+");
    fwrite($fp, $attachment['attachment']);
    fclose($fp);
  }

  /**
   *  extracts attachment concents and encodes it accordingly
   *  @param int $i the counter for attachments
   */
  private function get_file_content($i) {
    $this->attachments[$i]['attachment'] = imap_fetchbody($this->imap, $this->emailNumber, $i + 1);
    if ($this->structure->parts[$i]->encoding == 3) {
      $this->attachments[$i]['attachment'] = base64_decode($this->attachments[$i]['attachment']);
    } elseif ($this->structure->parts[$i]->encoding == 4) {
      $this->attachments[$i]['attachment'] = quoted_printable_decode($this->attachments[$i]['attachment']);
    }
  }

  /**
   *  checks ifdparameters object
   *  @param int $i the counter for attachments
   */
  private function check_ifdparams($i) {
    foreach ($this->structure->parts[$i]->dparameters as $object) {
      if (strtolower($object->attribute) == 'filename') {
        $this->attachments[$i]['is_attachment'] = true;
        $this->attachments[$i]['filename'] = $object->value;
      }
    }
  }

  /**
   *  checks ifparameters object
   *  @param int $i the counter for attachments
   */
  private function check_ifparams($i) {
    foreach ($this->structure->parts[$i]->parameters as $object) {
      if (strtolower($object->attribute) == 'name') {
        $this->attachments[$i]['is_attachment'] = true;
        $this->attachments[$i]['name'] = $object->value;
      }
    }
  }

  /**
   *  creates an empty array with default values for an attachment
   *  @param int $i the counter for attachments
   */
  private function create_new_array($i) {
    $this->attachments[$i] = array(
        'is_attachment' => false,
        'filename' => '',
        'name' => '',
        'attachment' => ''
    );
  }

  /**
   * 
   * @return type
   */
  public function getMailDatas() {
    return ['emailNumber' => $this->emailNumber, 'overview' => $this->overview, 'attachments' => $this->attachments];
  }

  /**
   * @param type $message_nums ($this->emailNumber) : message_nums est un intervalle, et pas seulement une liste de messages (comme décrit dans la » RFC2060).
   * @param type $mailbox : Le nom de la boîte aux lettres, voir la documentation de la fonction imap_open() pour plus de détails
   */
  public function moveEmail($mailbox = "INBOX/Saved") {
    //move the email to our saved folder
    try {
      if (!empty($this->emailNumber)) {
        $imapresult = imap_mail_move($this->imap, $this->emailNumber . ':' . $this->emailNumber, $mailbox);
        if ($imapresult == false) {
          die(imap_last_error());
        } else {
          //Efface tous les messages marqués pour l'effacement par imap_delete(), imap_mail_move(), ou imap_setflag_full().
          imap_expunge($this->imap);
        }
      }
    } catch (Exception $e) {
      echo "Erreur " . $e->getMessage();
    }
  }

}
