#!/usr/bin/php
<?php
if ('cli' != php_sapi_name()) die();

ini_set('memory_limit','128M');
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../../').'/');
require_once DOKU_INC.'inc/init.php';
require_once DOKU_INC.'inc/cliopts.php';

// handle options
$short_opts = 'hr';
$long_opts  = array('help', 'run', 'git-dir=', 'branch=', 'quiet');

$OPTS = Doku_Cli_Opts::getOptions(__FILE__, $short_opts, $long_opts);

if ( $OPTS->isError() ) {
    fwrite( STDERR, $OPTS->getMessage() . "\n");
    exit(1);
}

// handle '--help' option
if ( $OPTS->has('h') or $OPTS->has('help') or empty($OPTS->options) ) {
    usage();
    exit(0);
}

$importer = new git_importer();

// handle '--git-dir' option
if ( $OPTS->has('git-dir') ) {
    $importer->git_dir = getSuppliedArgument($OPTS, null, 'git-dir');
}

// handle '--branch' option
if ( $OPTS->has('branch') ) {
    $importer->git_branch = getSuppliedArgument($OPTS, null, 'branch');
}

// handle '--quiet' option
if ( $OPTS->has('quiet') ) {
    $importer->quiet = true;
}

// handle '--run' option
if ( $OPTS->has('r') or $OPTS->has('run') ) {
    $importer->import();
}

function usage() {
    print <<<'EOF'
    Usage: importer.php [options]

    Imports DokuWiki data into git repo.

    OPTIONS
        -h, --help     show this help and exit
        -r, --run      run importer
        --git-dir      defines the git repo path (overwrites $conf['repoPath'])
        --branch       defines the git branch to import (overwrites $conf['gitBranch'])
        --quiet        do not output message during processing

EOF;
}

function getSuppliedArgument($OPTS, $short, $long) {
    $arg = $OPTS->get($short);
    if ( is_null($arg) ) {
        $arg = $OPTS->get($long);
    }
    return $arg;
}

class git_importer {

    function __construct() {
        global $conf;
        $this->work_dir = realpath(DOKU_INC.$this->getConf('repoWorkDir'));
        $this->temp_dir = $conf['tmpdir'].'/gitbacked/importer';
        io_mkdir_p($this->temp_dir);
        $this->backup =& plugin_load('helper', 'gitbacked_backup');
    }

    function import() {
        global $conf, $lang;
        print 'start import'."\n";

        // acquire a lock, or exit
        $lockfile = $this->temp_dir.'.lock';
        $lock = fopen($lockfile, 'w+');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            print 'another instance of importer is running, exit'."\n";
            exit(1);
        }

        // init git repo
        $repo =& plugin_load('helper', 'gitbacked_git');
        $repo->setGitRepo($this->git_dir, $this->temp_dir, $this->git_branch);

        // collect history
        print 'collecting history...'."\n";

        // search and record all page-ids and media-ids
        $pagelist = $this->temp_dir.'/pagelist.txt';
        $this->getPageList($pagelist);
        $medialist = $this->temp_dir.'/medialist.txt';
        $this->getMediaList($medialist);

        // make history list
        // format: <logline> <tab> <data-type> <tab> <data-file>
        $historylist = $this->temp_dir.'/historylist.txt';
        $stream = fopen($historylist, "wb");
        $this->processPageList($pagelist, $stream);
        $this->processMediaList($medialist, $stream);
        fclose($stream);

        // sort history list, using shell utility to prevent memory issue
        $historylisttemp = $this->temp_dir.'/historylist.txt.tmp';
        rename($historylist, $historylisttemp);
        passthru(sprintf(
            'sort -n %s >%s',
            escapeshellarg($historylisttemp),
            escapeshellarg($historylist)
        ));

        // import from history list
        print 'start import...'."\n";
        $this->importHistory($repo, $historylist);

        // unlock, clean, and done
        print 'clean up...'."\n";
        $this->clearDir($this->temp_dir);
        print 'done.'."\n";
        flock($lock, LOCK_UN);
        @unlink($lockfile);
    }

    private function getPageList($listfile) {
        global $conf;
        // sort cannot output to the same file, write to a temp file first
        $tmpfile = $listfile.'.tmp';
        $lh = fopen($tmpfile, 'wb');
        // meta
        $data = array();
        search($data, $conf['metadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'filematch' => '\.changes$'
            ));
        foreach($data as $item) {
            $id = substr($item['id'], 0, -8);  // strip '.changes'
            fwrite( $lh, $id."\n" );
        }
        // meta.bak
        $data = array();
        search($data, $this->backup->conf('metadir'), 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'filematch' => '\.changes$'
            ));
        foreach($data as $item) {
            $id = substr($item['id'], 0, -8);  // strip '.changes'
            fwrite( $lh, $id."\n" );
        }
        // pages
        $data = array();
        search($data, $conf['datadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'pagesonly' => true
            ));
        foreach($data as $item) {
            $id = $item['id'];  // no additional ext here
            fwrite( $lh, $id."\n" );
        }
        fclose($lh);
        // sort and unique the history, using shell to prevent memory issue
        passthru(sprintf(
            'sort %s | uniq >%s',
            escapeshellarg($tmpfile),
            escapeshellarg($listfile)
        ));
    }

    private function getMediaList($listfile) {
        global $conf;
        // sort cannot output to the same file, write to a temp file first
        $tmpfile = $listfile.'.tmp';
        $lh = fopen($tmpfile, 'wb');
        // media_meta
        $data = array();
        search($data, $conf['mediametadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'filematch' => '\.changes$'
            ));
        foreach($data as $item) {
            $id = substr($item['id'], 0, -8);  // strip '.changes'
            fwrite( $lh, $id."\n" );
        }
        // media_meta.bak
        $data = array();
        search($data, $this->backup->conf('mediametadir'), 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'filematch' => '\.changes$'
            ));
        foreach($data as $item) {
            $id = substr($item['id'], 0, -8);  // strip '.changes'
            fwrite( $lh, $id."\n" );
        }
        // media
        $data = array();
        search($data, $conf['mediadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true
            ));
        foreach($data as $item) {
            $id = $item['id'];  // no additional ext here
            fwrite( $lh, $id."\n" );
        }
        fclose($lh);
        // sort and unique the history, using shell command to prevent memory issue
        passthru(sprintf(
            'sort %s | uniq >%s',
            escapeshellarg($tmpfile),
            escapeshellarg($listfile)
        ));
    }

    private function processPageList($listfile, $stream) {
        global $lang;
        $tmpfile = $this->temp_dir.'/changes.txt.tmp';
        $tmpfilesort = $this->temp_dir.'/changes.txt';
        $lh = fopen($listfile, 'rb');
        while (!feof($lh)) {
            $id = rtrim(fgets($lh), "\r\n");
            if ($id) {
                if (!$this->quiet) print "collecting history of page `$id'"."\n";
                $datafile = wikiFN($id, '', false);
                $metafile = metaFN($id, '.changes');
                $metafile2 = $this->backup->metaFN($id, '.changes');

                // read meta and meta.bak
                $th = fopen($tmpfile, 'wb');
                if (is_file($metafile)) {
                    $fh = fopen($metafile, "rb");
                    while (!feof($fh)) {
                        $logline = rtrim(fgets($fh), "\r\n");
                        if ($logline) {
                            $logline = $this->packHistoryLine($logline, $id, "attic");
                            fwrite( $th, $logline );
                        }
                    }
                    fclose($fh);
                }
                if (is_file($metafile2)) {
                    $fh = fopen($metafile2, "rb");
                    while (!feof($fh)) {
                        $logline = rtrim(fgets($fh), "\r\n");
                        if ($logline) {
                            $logline = $this->packHistoryLine($logline, $id, "attic", "hide");
                            fwrite( $th, $logline );
                        }
                    }
                    fclose($fh);
                }
                fclose($th);
                // sort history entries, using shell command to prevent memory issue
                passthru(sprintf(
                    'sort -n %s >%s',
                    escapeshellarg($tmpfile),
                    escapeshellarg($tmpfilesort)
                ));
                // process the history entries
                if (filesize($tmpfilesort)>0) {
                    // record the last line
                    $th = fopen($tmpfilesort, 'rb');
                    // pos end-1 should be a linefeed, skip it
                    // look back for 2nd-last linefeed or file start
                    for($x=-2; ; $x--) {
                        if (fseek($th, $x, SEEK_END) === -1) {
                            rewind($th);  // reset pointer in case 1 due to last fget()
                            break;
                        }
                        if (fgetc($th) == "\n") break;
                    }
                    $lastline = fgets($th);
                    list( $logline, $data_id, $data_type, $data_extra) = $this->unpackHistoryLine($lastline);
                    $lastlogline = $logline;
                    $lastdate = intval($logline[0]);
                    $lastaction = $logline[2];
                    // write to the stream
                    rewind($th);
                    while (!feof($th)) {
                        fwrite($stream, fgets($th));
                    }
                    fclose($th);
                }
                else {
                    $lastline = null;
                }

                // read pages
                if (is_file($datafile)) {
                    $datadate = filemtime($datafile);
                    // there's a newer external edit on the page
                    // fake a logline for later process
                    if (!$lastline || ($datadate > $lastdate)) {
                        $logline = array(
                            'date'  => $datadate,
                            'ip'    => '127.0.0.1',
                            'type'  => DOKU_CHANGE_TYPE_EDIT,
                            'id'    => $id,
                            'user'  => '',
                            'sum'   => $lang['external_edit'],
                            'extra' => ''
                        );
                        $logline = $this->packHistoryLine($logline, $id, "pages");
                        fwrite( $stream, $logline );
                    }
                    // page is latest revision, replace attic, which might not exist
                    else if ($lastline && ($datadate == $lastdate)) {
                        fseek( $stream, -strlen($lastline), SEEK_CUR );  // back to previous line
                        ftruncate($stream, ftell($stream));
                        $logline = $this->packHistoryLine($lastlogline, $id, "pages");
                        fwrite( $stream, $logline );
                    }
                }
                else {
                    // page is deleted externally after the latest wiki-edit
                    // fake a logline with action type "delete" for later process
                    // exact time is impossible to determine, pretend $lastdate + 1 (to sort after)
                    if ($lastline && ($lastaction != "D")) {
                        $logline = array(
                            'date'  => $lastdate + 1,
                            'ip'    => '127.0.0.1',
                            'type'  => DOKU_CHANGE_TYPE_DELETE,
                            'id'    => $id,
                            'user'  => '',
                            'sum'   => $lang['external_edit'],
                            'extra' => ''
                        );
                        $logline = $this->packHistoryLine($logline, $id, "pages");
                        fwrite( $stream, $logline );
                    }
                }
            }
        }
        fclose($lh);
    }

    private function processMediaList($listfile, $stream) {
        global $lang;
        $tmpfile = $this->temp_dir.'/changes.txt.tmp';
        $tmpfilesort = $this->temp_dir.'/changes.txt';
        $lh = fopen($listfile, 'rb');
        while (!feof($lh)) {
            $id = rtrim(fgets($lh), "\r\n");
            if ($id) {
                if (!$this->quiet) print "collecting history of media `$id'"."\n";
                $datafile = mediaFN($id);
                $metafile = mediaMetaFN($id, '.changes');
                $metafile2 = $this->backup->mediaMetaFN($id, '.changes');

                // read media_meta and media_meta.bak
                $th = fopen($tmpfile, 'wb');
                if (is_file($metafile)) {
                    $fh = fopen($metafile, "rb");
                    while (!feof($fh)) {
                        $logline = rtrim(fgets($fh), "\r\n");
                        if ($logline) {
                            $logline = $this->packHistoryLine($logline, $id, "media_attic");
                            fwrite( $th, $logline );
                        }
                    }
                    fclose($fh);
                }
                if (is_file($metafile2)) {
                    $fh = fopen($metafile2, "rb");
                    while (!feof($fh)) {
                        $logline = rtrim(fgets($fh), "\r\n");
                        if ($logline) {
                            $logline = $this->packHistoryLine($logline, $id, "media_attic", "hide");
                            fwrite( $th, $logline );
                        }
                    }
                    fclose($fh);
                }
                fclose($th);
                // sort history entries, using shell command to prevent memory issue
                passthru(sprintf(
                    'sort -n %s >%s',
                    escapeshellarg($tmpfile),
                    escapeshellarg($tmpfilesort)
                ));
                // process the history entries
                if (filesize($tmpfilesort)>0) {
                    // record the last line
                    $th = fopen($tmpfilesort, 'rb');
                    // pos end-1 should be a linefeed, skip it
                    // look back for 2nd-last linefeed or file start
                    for($x=-2; ; $x--) {
                        if (fseek($th, $x, SEEK_END) === -1) {
                            rewind($th);  // reset pointer in case 1 due to last fget()
                            break;
                        }
                        if (fgetc($th) == "\n") break;
                    }
                    $lastline = fgets($th);
                    list( $logline, $data_id, $data_type, $data_extra) = $this->unpackHistoryLine($lastline);
                    $lastlogline = $logline;
                    $lastdate = intval($logline[0]);
                    $lastaction = $logline[2];
                    // write to the stream
                    rewind($th);
                    while (!feof($th)) {
                        fwrite($stream, fgets($th));
                    }
                    fclose($th);
                }
                else {
                    $lastline = null;
                }

                // read media
                if (is_file($datafile)) {
                    $datadate = filemtime($datafile);
                    // there's a newer external edit on the media
                    // fake a logline for later process
                    if (!$lastline || ($datadate > $lastdate)) {
                        $logline = array(  // fake a logline
                            'date'  => $datadate,
                            'ip'    => '127.0.0.1',
                            'type'  => DOKU_CHANGE_TYPE_EDIT,
                            'id'    => $id,
                            'user'  => '',
                            'sum'   => $lang['external_edit'],
                            'extra' => ''
                        );
                        $logline = $this->packHistoryLine($logline, $id, "media");
                        fwrite( $stream, $logline );
                    }
                    // media is latest revision, replace attic, which might not exist
                    else if ($lastline && ($datadate == $lastdate)) {
                        fseek( $stream, -strlen($lastline), SEEK_CUR );  // back to previous line
                        ftruncate($stream, ftell($stream));
                        $logline = $this->packHistoryLine($lastlogline, $id, "media");
                        fwrite( $stream, $logline );
                    }
                }
                else {
                    // media is deleted externally after the latest wiki-edit
                    // fake a logline with action type "delete" for later process
                    // exact time is impossible to determine, pretend $lastdate + 1 (to sort after)
                    if ($lastline && ($lastaction != "D")) {
                        $logline = array(
                            'date'  => $lastdate + 1,
                            'ip'    => '127.0.0.1',
                            'type'  => DOKU_CHANGE_TYPE_DELETE,
                            'id'    => $id,
                            'user'  => '',
                            'sum'   => $lang['external_edit'],
                            'extra' => ''
                        );
                        $logline = $this->packHistoryLine($logline, $id, "media");
                        fwrite( $stream, $logline );
                    }
                }
            }
        }
        fclose($lh);
    }

    private function importHistory($repo, $historyfile) {
        // common settings
        $base = $this->work_dir;
        $base_cut = strlen($base);
        $histmeta =& plugin_load('helper', 'gitbacked_histmeta');

        // read history entries line by line and process them
        $hh = fopen($historyfile, "rb");
        while (!feof($hh)) {
            $line = rtrim(fgets($hh), "\r\n");
            if (!$line) continue;

            // reset commands
            $commands = array();

            // read info from a line
            list( $logline, $data_id, $data_type, $data_extra) = $this->unpackHistoryLine($line);
            list( $date, $ip, $type, $id, $user, $summary, $extra ) = $logline;

            if (!$this->quiet) print "importing from `$data_type': `$data_id'"."\n";

            // add data to commit
            $info = array($data_id);
            switch ($data_type) {
                case "pages":
                case "attic":
                    $info[] = "page";
                    $item = wikiFN($data_id, '', false);
                    $file = $this->temp_dir.substr($item, $base_cut);
                    io_mkdir_p(dirname($file));
                    if ($type == 'D') {
                        $repo->git('rm --cached --ignore-unmatch -- '.escapeshellarg($file));
                        $message = str_replace(
                            array('%page%', '%summary%', '%user%'),
                            array($data_id, $summary, $user),
                            $this->getConf('commitPageMsgDel')
                        );
                    }
                    else {
                        $datafile = ($data_type=='pages') ? $item : wikiFN($data_id, $date, false);
                        // history entry exist, data missing?
                        // or hidden if found in the backup directory
                        if (!is_file($datafile)) {
                            $datafile = $this->backup->wikiFN($id, $date, false);
                            if (is_file($datafile)) $commands[] = "hide data";
                        }
                        if (is_file($datafile)) {
                            file_put_contents($file, io_readFile($datafile, false));
                            $repo->git('add -- '.escapeshellarg($file));
                        }
                        $message = str_replace(
                            array('%page%', '%summary%', '%user%'),
                            array($data_id, $summary, $user),
                            $this->getConf('commitPageMsg')
                        );
                    }
                    break;
                case "media":
                case "media_attic":
                    $info[] = "media";
                    $item = mediaFN($data_id, '');
                    $file = $this->temp_dir.substr($item, $base_cut);
                    io_mkdir_p(dirname($file));
                    if ($type == 'D') {
                        $repo->git('rm --cached --ignore-unmatch -- '.escapeshellarg($file));
                        $message = str_replace(
                            array('%media%', '%user%'),
                            array($data_id, $user),
                            $this->getConf('commitMediaMsgDel')
                        );
                    }
                    else {
                        $datafile = ($data_type=='media') ? $item : mediaFN($data_id, $date);
                        // history entry exist, data missing?
                        // or hidden if found in the backup directory
                        if (!is_file($datafile)) {
                            $datafile = $this->backup->mediaFN($id, $date);
                            if (is_file($datafile)) $commands[] = "hide data";
                        }
                        if (is_file($datafile)) {
                            copy($datafile, $file);
                            $repo->git('add -- '.escapeshellarg($file));
                        }
                        $message = str_replace(
                            array('%media%', '%user%'),
                            array($data_id, $user),
                            $this->getConf('commitMediaMsg')
                        );
                    }
                    break;
            }
            if ($data_extra == 'hide') $commands[] = 'hide change';

            // now commit
            $commit_message = $histmeta->pack($message, $logline, $info, $commands);
            $commit_date = $date;
            $repo->git('commit --allow-empty -m '.escapeshellarg($commit_message).' --date '.escapeshellarg($commit_date));
        }
        fclose($hh);
    }

    // this script is not a true plugin, fake this method for convenience
    private function getConf($setting, $notset=false) {
        $my =& plugin_load('helper', 'gitbacked_git');
        return $my->getConf($setting, $notset);
    }

    private function clearDir($dir) {
        $dh = @opendir($dir);
        if($dh) {
            while(($file = readdir($dh)) !== false){
                if ($file{0}=='.') continue;
                $subfile = $dir.'/'.$file;
                if (is_file($subfile)) unlink($subfile);
                else $this->clearDir($subfile);
            }
            closedir($dh);
            rmdir($dir);
            return true;
        }
        return false;
    }

    /**
     * Packs information into a single history line
     *
     * @param  mixed   the original dokuwiki logline, array or string, with or without a linefeed
     * @param  string  the resource id to access
     * @param  string  the resource type
     * @param  string  additional information such as hidden
     * @return string  an entry line, with linefeed
     */
    private function packHistoryLine($logline, $data_id="", $data_type="", $data_extra="") {
        if (is_array($logline)) $logline = implode("\t", $logline);
        $logline = rtrim($logline, "\r\n");
        $packed = array($logline, $data_id, $data_type, $data_extra);
        return implode("\t", $packed)."\n";
    }

    /**
     * Unpacks information from a single history line
     *
     * @param  string  a line packed by packHistoryLine, with or without a linefeed
     * @return array   array(array $logline, string $data_id, string $data_type, string $data_extra)
     */
    private function unpackHistoryLine($packed) {
        $packed = rtrim($packed, "\r\n");
        $unpacked = explode("\t", $packed);
        $data_extra = array_pop($unpacked);
        $data_type = array_pop($unpacked);
        $data_id = array_pop($unpacked);
        $logline = $unpacked;
        return array($logline, $data_id, $data_type, $data_extra);
    }

}
