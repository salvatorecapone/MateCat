<?php
/**
 * Created by JetBrains PhpStorm.
 * User: domenico
 * Date: 22/10/13
 * Time: 17.25
 *
 */
include_once INIT::$UTILS_ROOT . "/xliff.parser.1.3.class.php";
include_once INIT::$UTILS_ROOT . "/DetectProprietaryXliff.php";

class ProjectManager {

    protected $projectStructure;

    protected $mysql_link;

    public function __construct( ArrayObject $projectStructure = null ){

        if ( $projectStructure == null ) {
            $projectStructure = new RecursiveArrayObject(
                array(
                    'id_project'         => null,
                    'id_customer'        => null,
                    'user_ip'            => null,
                    'project_name'       => null,
                    'result'             => null,
                    'private_tm_key'     => 0,
                    'private_tm_user'    => null,
                    'private_tm_pass'    => null,
                    'array_files'        => array(), //list of file names
                    'file_id_list'       => array(),
                    'file_references'    => array(),
                    'source_language'    => null,
                    'target_language'    => null,
                    'mt_engine'          => null,
                    'tms_engine'         => null,
                    'ppassword'          => null,
                    'array_jobs'         => array( 'job_list' => array(), 'job_pass' => array(),'job_segments' => array() ),
                    'job_segments'       => array(), //array of job_id => array( min_seg, max_seg )
                    'segments'           => array(), //array of files_id => segmentsArray()
                    'translations'       => array(), //one translation for every file because translations are files related
                    'query_translations' => array(),
                    'status'             => 'NOT_READY_FOR_ANALYSIS',
                    'job_to_split'       => null,
                    'job_to_split_pass'  => null,
                    'split_result'       => null,
                    'job_to_merge'       => null,
                ) );
        }

        $this->projectStructure = $projectStructure;

        $mysql_hostname = INIT::$DB_SERVER;   // Database Server machine
        $mysql_database = INIT::$DB_DATABASE;     // Database Name
        $mysql_username = INIT::$DB_USER;   // Database User
        $mysql_password = INIT::$DB_PASS;

        $this->mysql_link = mysql_connect($mysql_hostname, $mysql_username, $mysql_password);
        mysql_select_db($mysql_database, $this->mysql_link);
        
    }

    public function getProjectStructure(){
        return $this->projectStructure;
    }

    public function createProject() {

        // project name sanitize
        $oldName = $this->projectStructure['project_name'];
        $this->projectStructure['project_name'] = $this->_sanitizeName( $this->projectStructure['project_name'] );
        if( $this->projectStructure['project_name'] == false ){
            $this->projectStructure['result'][ 'errors' ][ ] = array( "code" => -5, "message" => "Invalid Project Name " . $oldName . ": it should only contain numbers and letters!" );
            return false;
        }

        // create project
        $this->projectStructure['ppassword']   = $this->_generatePassword();
        $this->projectStructure['user_ip']     = Utils::getRealIpAddr();
        $this->projectStructure['id_customer'] = 'translated_user';

        $this->projectStructure['id_project'] = insertProject( $this->projectStructure );

        //create user (Massidda 2013-01-24)
        //this is done only if an API key is provided
        if ( !empty( $this->projectStructure['private_tm_key'] ) ) {
            //the base case is when the user clicks on "generate private TM" button:
            //a (user, pass, key) tuple is generated and can be inserted
            //if it comes with it's own key without querying the creation api, create a (key,key,key) user
            if ( empty( $this->projectStructure['private_tm_user'] ) ) {
                $this->projectStructure['private_tm_user'] = $this->projectStructure['private_tm_key'];
                $this->projectStructure['private_tm_pass'] = $this->projectStructure['private_tm_key'];
            }

            insertTranslator( $this->projectStructure );

        }


        $uploadDir = INIT::$UPLOAD_REPOSITORY . "/" . $_COOKIE['upload_session'];
        foreach ( $this->projectStructure['array_files'] as $fileName ) {

            /**
             * Conversion Enforce
             *
             * Check Extension no more sufficient, we want check content
             * if this is an idiom xlf file type, conversion are enforced
             * $enforcedConversion = true; //( if conversion is enabled )
             */
            $enforcedConversion = false;
            try {

                $fileType = DetectProprietaryXliff::getInfo( INIT::$UPLOAD_REPOSITORY. '/' .$_COOKIE['upload_session'].'/' . $fileName );
                //Log::doLog( 'Proprietary detection: ' . var_export( $fileType, true ) );

                if( $fileType['proprietary'] == true  ){

                    if( INIT::$CONVERSION_ENABLED && $fileType['proprietary_name'] == 'idiom world server' ){
                        $enforcedConversion = true;
                        Log::doLog( 'Idiom found, conversion Enforced: ' . var_export( $enforcedConversion, true ) );

                    } else {
                        /**
                         * Application misconfiguration.
                         * upload should not be happened, but if we are here, raise an error.
                         * @see upload.class.php
                         * */
                        $this->projectStructure['result']['errors'][] = array("code" => -8, "message" => "Proprietary xlf format detected. Not able to import this XLIFF file. ($fileName)");
                        setcookie("upload_session", "", time() - 10000);
                        return;
                        //stop execution
                    }
                }
            } catch ( Exception $e ) { Log::doLog( $e->getMessage() ); }


            $mimeType = pathinfo( $fileName , PATHINFO_EXTENSION );

            $original_content = "";
            if ( ( ( $mimeType != 'sdlxliff' && $mimeType != 'xliff' && $mimeType != 'xlf' ) || $enforcedConversion ) && INIT::$CONVERSION_ENABLED ) {

                //converted file is inside "_converted" directory
                $fileDir          = $uploadDir . '_converted';
                $original_content = file_get_contents( "$uploadDir/$fileName" );
                $sha1_original    = sha1( $original_content );
                $original_content = gzdeflate( $original_content, 5 );

                //file name is a xliff converted like: 'a_word_document.doc.sdlxliff'
                $real_fileName = $fileName . '.sdlxliff';

            } else {

                //filename is already an xliff and it is in a canonical normal directory
                $sha1_original = "";
                $fileDir = $uploadDir;
                $real_fileName = $fileName;
            }

            $filePathName = $fileDir . '/' . $real_fileName;

            if ( !file_exists( $filePathName ) ) {
                $this->projectStructure[ 'result' ][ 'errors' ][ ] = array( "code" => -6, "message" => "File not found on server after upload." );
            }

            $contents = file_get_contents($filePathName);

            try {

                $fid = insertFile( $this->projectStructure, $fileName, $mimeType, $contents, $sha1_original, $original_content );
                $this->projectStructure[ 'file_id_list' ]->append( $fid );

                $this->_extractSegments( $filePathName, $fid );

                //Log::doLog( $this->projectStructure['segments'] );

            } catch ( Exception $e ){

                if ( $e->getCode() == -1 ) {
                    $this->projectStructure['result']['errors'][] = array("code" => -7, "message" => "No segments found in $fileName");
                } elseif( $e->getCode() == -2 ) {
                    $this->projectStructure['result']['errors'][] = array("code" => -7, "message" => "Failed to store segments in database for $fileName");
                } elseif( $e->getCode() == -3 ) {
                    $this->projectStructure['result']['errors'][] = array("code" => -7, "message" => "File $fileName not found. Failed to save XLIFF conversion on disk");
                } elseif( $e->getCode() == -4 ) {
                    $this->projectStructure['result']['errors'][] = array("code" => -7, "message" => "Internal Error. Xliff Import: Error parsing. ( $fileName )");
                } elseif( $e->getCode() == -11 ) {
                    $this->projectStructure['result']['errors'][] = array("code" => -7, "message" => "Failed to store reference files on disk. Permission denied");
                } elseif( $e->getCode() == -12 ) {
                    $this->projectStructure['result']['errors'][] = array("code" => -7, "message" => "Failed to store reference files in database");
                } else {
                    //mysql insert Blob Error
                    $this->projectStructure['result']['errors'][] = array("code" => -7, "message" => "File is Too large. ( $fileName )");
                }

                Log::doLog( $e->getMessage() );

            }

            //exit;
        }

        if( !empty( $this->projectStructure['result']['errors'] ) ){
            Log::doLog( "Project Creation Failed. Sent to Output all errors." );
            Log::doLog( $this->projectStructure['result']['errors'] );
            return false;
        }

        //Log::doLog( array_pop( array_chunk( $SegmentTranslations[$fid], 25, true ) ) );
        //create job

        if (isset($_SESSION['cid']) and !empty($_SESSION['cid'])) {
            $owner = $_SESSION['cid'];
        } else {
            $_SESSION['_anonym_pid'] = $this->projectStructure['id_project'];
            //default user
            $owner = '';
        }


        $isEmptyProject = false;
        //Throws exception
        try {
            $this->_createJobs( $this->projectStructure, $owner );

            //FIXME for project with pre translation this query is not enough,
            //we need compare the number of segments with translations, but take an eye to the opensource

            $query_visible_segments = "SELECT count(*) as cattool_segments
                                        FROM segments WHERE id_file IN ( %s ) and show_in_cattool = 1";

            $string_file_list = implode( "," , $this->projectStructure['file_id_list']->getArrayCopy() );
            $query_visible_segments = sprintf( $query_visible_segments, $string_file_list );

            $res = mysql_query( $query_visible_segments, $this->mysql_link );

            if ( !$res ) {
                Log::doLog("Segment Search: Failed Retrieve min_segment/max_segment for files ( $string_file_list ) - DB Error: " . mysql_error() . " - \n");
                throw new Exception( "Segment Search: Failed Retrieve min_segment/max_segment for job", -5);
            }

            $rows = mysql_fetch_assoc( $res );

            if ( $rows['cattool_segments'] == 0  ) {
                Log::doLog("Segment Search: No segments in this project - \n");
                $isEmptyProject = true;
            }

        } catch ( Exception $ex ){
            $this->projectStructure['result']['errors'][] = array( "code" => -9, "message" => "Fail to create Job. ( {$ex->getMessage()} )" );
            return false;
        }

        self::_deleteDir( $uploadDir );
        if ( is_dir( $uploadDir . '_converted' ) ) {
            self::_deleteDir( $uploadDir . '_converted' );
        }

        $this->projectStructure['status'] = ( INIT::$VOLUME_ANALYSIS_ENABLED ) ? 'NEW' : 'NOT_TO_ANALYZE';
        if( $isEmptyProject ){
            $this->projectStructure['status'] = 'EMPTY';
        }

        changeProjectStatus( $this->projectStructure['id_project'], $this->projectStructure['status'] );
        $this->projectStructure['result'][ 'code' ]            = 1;
        $this->projectStructure['result'][ 'data' ]            = "OK";
        $this->projectStructure['result'][ 'ppassword' ]       = $this->projectStructure['ppassword'];
        $this->projectStructure['result'][ 'password' ]        = $this->projectStructure['array_jobs']['job_pass'];
        $this->projectStructure['result'][ 'id_job' ]          = $this->projectStructure['array_jobs']['job_list'];
        $this->projectStructure['result'][ 'job_segments' ]    = $this->projectStructure['array_jobs']['job_segments'];
        $this->projectStructure['result'][ 'id_project' ]      = $this->projectStructure['id_project'];
        $this->projectStructure['result'][ 'project_name' ]    = $this->projectStructure['project_name'];
        $this->projectStructure['result'][ 'source_language' ] = $this->projectStructure['source_language'];
        $this->projectStructure['result'][ 'target_language' ] = $this->projectStructure['target_language'];
        $this->projectStructure['result'][ 'status' ]          = $this->projectStructure['status'];

    }

    protected function _createJobs( ArrayObject $projectStructure, $owner ) {

        foreach ( $projectStructure['target_language'] as $target ) {

            $query_min_max = "SELECT MIN( id ) AS job_first_segment , MAX( id ) AS job_last_segment
                                FROM segments WHERE id_file IN ( %s )";

            $string_file_list = implode( "," , $projectStructure['file_id_list']->getArrayCopy() );
            $last_segments_query = sprintf( $query_min_max, $string_file_list );
            $res = mysql_query( $last_segments_query, $this->mysql_link );

            if ( !$res || mysql_num_rows( $res ) == 0 ) {
                Log::doLog("Segment Search: Failed Retrieve min_segment/max_segment for files ( $string_file_list ) - DB Error: " . mysql_error() . " - \n");
                throw new Exception( "Segment import - DB Error: " . mysql_error(), -5);
            }

            //IT IS EVERY TIME ONLY A LINE!! don't worry about a cycle
            $job_segments = mysql_fetch_assoc( $res );

            $password = $this->_generatePassword();
            $jid = insertJob( $projectStructure, $password, $target, $job_segments, $owner );
            $projectStructure['array_jobs']['job_list']->append( $jid );
            $projectStructure['array_jobs']['job_pass']->append( $password );
            $projectStructure['array_jobs']['job_segments']->offsetSet( $jid . "-" . $password, $job_segments );

            foreach ( $projectStructure['file_id_list'] as $fid ) {

                try {
                    //prepare pre-translated segments queries
                    if( !empty( $projectStructure['translations'] ) ){
                        $this->_insertPreTranslations( $jid );
                    }
                } catch ( Exception $e ) {
                    $msg = "\n\n Error, pre-translations lost, project should be re-created. \n\n " . var_export( $e->getMessage(), true );
                    Utils::sendErrMailReport($msg);
                }

                insertFilesJob($jid, $fid);

            }

        }

    }

    /**
     *
     * Build a job split structure, minimum split value are 2 chunks
     *
     * @param ArrayObject $projectStructure
     * @param int         $num_split
     * @param array       $requestedWordsPerSplit Matecat Equivalent Words ( Only valid for Pro Version )
     *
     * @return RecursiveArrayObject
     *
     * @throws Exception
     */
    public function getSplitData( ArrayObject $projectStructure, $num_split = 2, $requestedWordsPerSplit = array() ) {

        $num_split = (int)$num_split;

        if( $num_split < 2 ){
            throw new Exception( 'Minimum Chunk number for split is 2.', -2 );
        }

        if( !empty( $requestedWordsPerSplit ) && count($requestedWordsPerSplit) != $num_split ){
            throw new Exception( "Requested words per chunk and Number of chunks not consistent.", -3 );
        }

        if( !empty( $requestedWordsPerSplit ) && !INIT::$VOLUME_ANALYSIS_ENABLED ){
            throw new Exception( "Requested words per chunk available only for Matecat PRO version", -4 );
        }

        /**
         * Select all rows raw_word_count and eq_word_count
         * and their totals ( ROLLUP )
         * reserve also two columns for job_first_segment and job_last_segment
         *
         * +----------------+-------------------+---------+-------------------+------------------+
         * | raw_word_count | eq_word_count     | id      | job_first_segment | job_last_segment |
         * +----------------+-------------------+---------+-------------------+------------------+
         * |          26.00 |             22.10 | 2390662 |           2390418 |          2390665 |
         * |          30.00 |             25.50 | 2390663 |           2390418 |          2390665 |
         * |          48.00 |             40.80 | 2390664 |           2390418 |          2390665 |
         * |          45.00 |             38.25 | 2390665 |           2390418 |          2390665 |
         * |        3196.00 |           2697.25 |    NULL |           2390418 |          2390665 |  -- ROLLUP ROW
         * +----------------+-------------------+---------+-------------------+------------------+
         *
         */
        $query = "SELECT
                        SUM( raw_word_count ) AS raw_word_count,
                        SUM(eq_word_count) AS eq_word_count,
                        job_first_segment, job_last_segment, s.id
                    FROM segments s
                    LEFT  JOIN segment_translations st ON st.id_segment = s.id
                    INNER JOIN jobs j ON j.id = st.id_job
                    WHERE s.id BETWEEN j.job_first_segment AND j.job_last_segment
                    AND j.id = %u
                    AND j.password = '%s'
                    GROUP BY s.id WITH ROLLUP";

        $query = sprintf( $query,
                            $projectStructure[ 'job_to_split' ],
                            $projectStructure[ 'job_to_split_pass' ]
        );

        $res   = mysql_query( $query, $this->mysql_link );

        //assignment in condition is often dangerous, deprecated
        while ( ( $rows[] = mysql_fetch_assoc( $res ) ) != false );
        array_pop( $rows ); //destroy last assignment row ( every time === false )

        if( empty( $rows ) ){
            throw new Exception( 'No segments found for job ' . $projectStructure[ 'job_to_split' ], -5 );
        }

        $row_totals     = array_pop( $rows ); //get the last row ( ROLLUP )
        unset($row_totals['id']);

        if( empty($row_totals['job_first_segment']) || empty($row_totals['job_last_segment']) ){
            throw new Exception('Wrong job id or password. Job segment range not found.', -6);
        }

        //if fast analysis with equivalent word count is present
        $count_type    = ( !empty( $row_totals[ 'eq_word_count' ] ) ? 'eq_word_count' : 'raw_word_count' );
        $total_words   = $row_totals[ $count_type ];

        if( empty( $requestedWordsPerSplit ) ){
            /*
             * Simple Split with pretty equivalent number of words per chunk
             */
            $words_per_job = array_fill( 0, $num_split, round( $total_words / $num_split, 0 ) );
        } else {
            /*
             * User defined words per chunk, needs some checks and control structures
             */
            $words_per_job = $requestedWordsPerSplit;
        }

        $counter = array();
        $chunk   = 0;

        $reverse_count = array( 'eq_word_count' => 0, 'raw_word_count' => 0 );

        foreach( $rows as $row ) {

            if( !array_key_exists( $chunk, $counter ) ){
                $counter[$chunk] = array(
                    'eq_word_count'  => 0,
                    'raw_word_count' => 0,
                    'segment_start'  => $row['id'],
                    'segment_end'    => 0,
                );
            }

            $counter[$chunk][ 'eq_word_count' ]  += $row[ 'eq_word_count' ];
            $counter[$chunk][ 'raw_word_count' ] += $row[ 'raw_word_count' ];
            $counter[$chunk][ 'segment_end' ]     = $row[ 'id' ];

            //check for wanted words per job
            //create a chunk when reach the requested number of words
            //and we are below the requested number of splits
            //so we add to the last chunk all rests
            if( $counter[$chunk][ $count_type ] >= $words_per_job[$chunk] && $chunk < $num_split -1 /* chunk is zero based */ ){
                $counter[$chunk][ 'eq_word_count' ]  = (int)$counter[$chunk][ 'eq_word_count' ];
                $counter[$chunk][ 'raw_word_count' ] = (int)$counter[$chunk][ 'raw_word_count' ];

                $reverse_count[ 'eq_word_count' ]   += (int)$counter[$chunk][ 'eq_word_count' ];
                $reverse_count[ 'raw_word_count' ]  += (int)$counter[$chunk][ 'raw_word_count' ];

                $chunk++;
            }

        }

        if( $total_words > $reverse_count[ $count_type ] ){
            $counter[$chunk][ 'eq_word_count' ]  = round( $row_totals[ 'eq_word_count' ] - $reverse_count[ 'eq_word_count' ] );
            $counter[$chunk][ 'raw_word_count' ] = round( $row_totals[ 'raw_word_count' ] - $reverse_count['raw_word_count'] );
        }

        if( count( $counter ) < 2 ){
            throw new Exception( 'The requested number of words for the first chunk is too large. I cannot create 2 chunks.', -7 );
        }

        $result = array_merge( $row_totals, array( 'chunks' => $counter ) );

        $projectStructure['split_result'] = new ArrayObject( $result );

        return $projectStructure['split_result'];

    }

    /**
     * Do the split based on previous getSplitData analysis
     * It clone the original job in the right number of chunks and fill these rows with:
     * first/last segments of every chunk, last opened segment as first segment of new job
     * and the timestamp of creation
     *
     * @param ArrayObject $projectStructure
     *
     * @throws Exception
     */
    protected function _splitJob( ArrayObject $projectStructure ){

        $query_job = "SELECT * FROM jobs WHERE id = %u AND password = '%s'";
        $query_job = sprintf( $query_job, $projectStructure[ 'job_to_split' ], $projectStructure[ 'job_to_split_pass' ] );
        //$projectStructure[ 'job_to_split' ]

        $jobInfo = mysql_query( $query_job, $this->mysql_link );
        $jobInfo = mysql_fetch_assoc( $jobInfo );

        $data = array();

        foreach( $projectStructure['split_result']['chunks'] as $chunk => $contents ){

            //IF THIS IS NOT the original job, DELETE relevant fields
            if( $contents['segment_start'] != $projectStructure['split_result']['job_first_segment'] ){
                //next insert
                $jobInfo['password'] =  $this->_generatePassword();
                $jobInfo['create_date']  = date('Y-m-d H:i:s');
            }

            $jobInfo['last_opened_segment'] = $contents['segment_start'];
            $jobInfo['job_first_segment'] = $contents['segment_start'];
            $jobInfo['job_last_segment']  = $contents['segment_end'];

            $query = "INSERT INTO jobs ( " . implode( ", ", array_keys( $jobInfo ) ) . " )
                        VALUES ( '" . implode( "', '", array_values( $jobInfo ) ) . "' )
                        ON DUPLICATE KEY UPDATE
                        job_first_segment = '{$jobInfo['job_first_segment']}',
                        job_last_segment = '{$jobInfo['job_last_segment']}'";


            //add here job id to list
            $projectStructure['array_jobs']['job_list']->append( $projectStructure[ 'job_to_split' ] );
            //add here passwords to list
            $projectStructure['array_jobs']['job_pass']->append( $jobInfo['password'] );

            $projectStructure['array_jobs']['job_segments']->offsetSet( $projectStructure[ 'job_to_split' ] . "-" . $jobInfo['password'], new ArrayObject( array( $contents['segment_start'], $contents['segment_end'] ) ) );

            $data[] = $query;
        }

        foreach( $data as $query ){
            $res = mysql_query( $query, $this->mysql_link );
            if( $res !== true ){
                $msg = "Failed to split job into " . count( $projectStructure['split_result']['chunks'] ) . " chunks\n";
                $msg .= "Tried to perform SQL: \n" . print_r(  $data ,true ) . " \n\n";
                $msg .= "Failed Statement is: \n" . print_r( $query, true ) . "\n";
                Utils::sendErrMailReport( $msg );
                throw new Exception( 'Failed to insert job chunk, project damaged.', -8 );
            }
        }

    }

    /**
     * Apply new structure of job
     *
     * @param ArrayObject $projectStructure
     */
    public function applySplit( ArrayObject $projectStructure ){
        $this->_splitJob( $projectStructure );
    }

    public function mergeALL( ArrayObject $projectStructure, $renewPassword = false ){

        $query_job = "SELECT *
                        FROM jobs
                        WHERE id = %u
                        ORDER BY job_first_segment";

        $query_job = sprintf( $query_job, $projectStructure[ 'job_to_merge' ] );
        //$projectStructure[ 'job_to_split' ]

        $jobInfo = mysql_query( $query_job, $this->mysql_link );

        //assignment in condition is often dangerous, deprecated
        while ( ( $rows[] = mysql_fetch_assoc( $jobInfo ) ) != false );
        array_pop( $rows ); //destroy last assignment row ( every time === false )

        //get the min and
        $first_job = reset( $rows );
        $job_first_segment = $first_job['job_first_segment'];

        //the max segment from job list
        $last_job = end( $rows );
        $job_last_segment = $last_job['job_last_segment'];

        //change values of first job
        $first_job['job_first_segment'] = $job_first_segment; // redundant
        $first_job['job_last_segment']  = $job_last_segment;

        $oldPassword = $first_job['password'];
        if ( $renewPassword ){
            $first_job['password'] = self::_generatePassword();
        }

        $_data = array();
        foreach( $first_job as $field => $value ){
            $_data[] = "`$field`='$value'";
        }

        //----------------------------------------------------

        $queries = array();

        $queries[] = "UPDATE jobs SET " . implode( ", \n", $_data ) .
                     " WHERE id = {$first_job['id']} AND password = '{$oldPassword}'"; //ose old password

        //delete all old jobs
        $queries[] = "DELETE FROM jobs WHERE id = {$first_job['id']} AND password != '{$first_job['password']}' "; //use new password


        foreach( $queries as $query ){
            $res = mysql_query( $query, $this->mysql_link );
            if( $res !== true ){
                $msg = "Failed to merge job  " . $rows[0]['id'] . " from " . count($rows) .  " chunks\n";
                $msg .= "Tried to perform SQL: \n" . print_r(  $queries ,true ) . " \n\n";
                $msg .= "Failed Statement is: \n" . print_r( $query, true ) . "\n";
                $msg .= "Original Status for rebuild job and project was: \n" . print_r( $rows, true ) . "\n";
                Utils::sendErrMailReport( $msg );
                throw new Exception( 'Failed to merge jobs, project damaged. Contact Matecat Support to rebuild project.', -8 );
            }
        }

    }

    protected function _extractSegments( $files_path_name, $fid ){

        $info = pathinfo( $files_path_name );

        //create Structure fro multiple files
        $this->projectStructure['segments']->offsetSet( $fid, new ArrayObject( array() ) );

        // Checking Extentions
        if (($info['extension'] == 'xliff') || ($info['extension'] == 'sdlxliff') || ($info['extension'] == 'xlf')) {
            $content = file_get_contents( $files_path_name );
        } else {
            throw new Exception( "Failed to find Xliff - no segments found", -3 );
        }

        $xliff_obj = new Xliff_Parser();
        $xliff = $xliff_obj->Xliff2Array($content);

        // Checking that parsing went well
        if ( isset( $xliff[ 'parser-errors' ] ) or !isset( $xliff[ 'files' ] ) ) {
            Log::doLog( "Xliff Import: Error parsing. " . join( "\n", $xliff[ 'parser-errors' ] ) );
            throw new Exception( "Xliff Import: Error parsing. Check Log file.", -4 );
        }

        // Creating the Query
        foreach ($xliff['files'] as $xliff_file) {

            if (!array_key_exists('trans-units', $xliff_file)) {
                continue;
            }

            //extract internal reference base64 files and store their index in $this->projectStructure
            $this->_extractFileReferences( $fid, $xliff_file );

            foreach ($xliff_file['trans-units'] as $xliff_trans_unit) {
                if (!isset($xliff_trans_unit['attr']['translate'])) {
                    $xliff_trans_unit['attr']['translate'] = 'yes';
                }
                if ($xliff_trans_unit['attr']['translate'] == "no") {

                } else {
                    // If the XLIFF is already segmented (has <seg-source>)
                    if (isset($xliff_trans_unit['seg-source'])) {

                        foreach ($xliff_trans_unit['seg-source'] as $position => $seg_source) {

                            $show_in_cattool = 1;
                            $tempSeg = strip_tags($seg_source['raw-content']);
                            $tempSeg = trim($tempSeg);

                            //init tags
                            $seg_source['mrk-ext-prec-tags'] = '';
                            $seg_source['mrk-ext-succ-tags'] = '';

                            if ( is_null($tempSeg) || $tempSeg === '' ) {
                                $show_in_cattool = 0;
                            } else {
                                $extract_external = $this->_strip_external($seg_source['raw-content']);
                                $seg_source['mrk-ext-prec-tags'] = $extract_external['prec'];
                                $seg_source['mrk-ext-succ-tags'] = $extract_external['succ'];
                                $seg_source['raw-content'] = $extract_external['seg'];

                                if( isset( $xliff_trans_unit['seg-target'][$position]['raw-content'] ) ){
                                    $target_extract_external = $this->_strip_external( $xliff_trans_unit['seg-target'][$position]['raw-content'] );

                                    //we don't want THE CONTENT OF TARGET TAG IF PRESENT and EQUAL TO SOURCE???
                                    //AND IF IT IS ONLY A CHAR? like "*" ?
                                    //we can't distinguish if it is translated or not
                                    //this means that we lose the tags id inside the target if different from source
                                    $src = strip_tags( html_entity_decode( $extract_external['seg'], ENT_QUOTES, 'UTF-8' ) );
                                    $trg = strip_tags( html_entity_decode( $target_extract_external['seg'], ENT_QUOTES, 'UTF-8' ) );

                                    if( $src != $trg && !is_numeric($src) ){ //treat 0,1,2.. as translated content!

                                        $target = CatUtils::placeholdnbsp($target_extract_external['seg']);
                                        $target = mysql_real_escape_string($target);

                                        //add an empty string to avoid casting to int: 0001 -> 1
                                        //useful for idiom internal xliff id
                                        $this->projectStructure['translations']->offsetSet( "" . $xliff_trans_unit[ 'attr' ][ 'id' ] , $target );

                                        //seg-source and target translation can have different mrk id
                                        //override the seg-source surrounding mrk-id with them of target
                                        $seg_source['mrk-ext-prec-tags'] = $target_extract_external['prec'];
                                        $seg_source['mrk-ext-succ-tags'] = $target_extract_external['succ'];

                                    }

                                }

                            }

                            //Log::doLog( $xliff_trans_unit ); die();

                            $seg_source[ 'raw-content' ] = CatUtils::placeholdnbsp( $seg_source[ 'raw-content' ] );

                            $mid                   = mysql_real_escape_string( $seg_source[ 'mid' ] );
                            $ext_tags              = mysql_real_escape_string( $seg_source[ 'ext-prec-tags' ] );
                            $source                = mysql_real_escape_string( $seg_source[ 'raw-content' ] );
                            $ext_succ_tags         = mysql_real_escape_string( $seg_source[ 'ext-succ-tags' ] );
                            $num_words             = CatUtils::segment_raw_wordcount( $seg_source[ 'raw-content' ] );
                            $trans_unit_id         = mysql_real_escape_string( $xliff_trans_unit[ 'attr' ][ 'id' ] );
                            $mrk_ext_prec_tags     = mysql_real_escape_string( $seg_source[ 'mrk-ext-prec-tags' ] );
                            $mrk_ext_succ_tags     = mysql_real_escape_string( $seg_source[ 'mrk-ext-succ-tags' ] );

                            if( $this->projectStructure['file_references']->offsetExists( $fid ) ){
                                $file_reference = $this->projectStructure['file_references'][$fid];
                            } else $file_reference = null;

                            $this->projectStructure['segments'][$fid]->append( "('$trans_unit_id',$fid,'$file_reference','$source',$num_words,'$mid','$ext_tags','$ext_succ_tags',$show_in_cattool,'$mrk_ext_prec_tags','$mrk_ext_succ_tags')" );

                        }

                    } else {
                        $show_in_cattool = 1;

                        $tempSeg = strip_tags( $xliff_trans_unit['source']['raw-content'] );
                        $tempSeg = trim($tempSeg);
                        $tempSeg = CatUtils::placeholdnbsp( $tempSeg );
                        $prec_tags = NULL;
                        $succ_tags = NULL;
                        if ( empty( $tempSeg ) || $tempSeg == NBSPPLACEHOLDER ) { //@see cat.class.php, ( DEFINE NBSPPLACEHOLDER ) don't show <x id=\"nbsp\"/>
                            $show_in_cattool = 0;
                        } else {
                            $extract_external                              = $this->_strip_external( $xliff_trans_unit[ 'source' ][ 'raw-content' ] );
                            $prec_tags= empty( $extract_external[ 'prec' ] ) ? null : $extract_external[ 'prec' ];
                            $succ_tags= empty( $extract_external[ 'succ' ] ) ? null : $extract_external[ 'succ' ];
                            $xliff_trans_unit[ 'source' ][ 'raw-content' ] = $extract_external[ 'seg' ];

                            if ( isset( $xliff_trans_unit[ 'target' ][ 'raw-content' ] ) ) {

                                 $target_extract_external = $this->_strip_external( $xliff_trans_unit[ 'target' ][ 'raw-content' ] );

                                 if ( $xliff_trans_unit[ 'source' ][ 'raw-content' ] != $target_extract_external[ 'seg' ] ) {

                                     $target = CatUtils::placeholdnbsp( $target_extract_external[ 'seg' ] );
                                     $target = mysql_real_escape_string( $target );

                                     //add an empty string to avoid casting to int: 0001 -> 1
                                     //useful for idiom internal xliff id
                                     $this->projectStructure['translations']->offsetSet( "" . $xliff_trans_unit[ 'attr' ][ 'id' ], new ArrayObject( array( 2 => $target ) ) );

                                 }

                            }
                        }

                        $source = CatUtils::placeholdnbsp( $xliff_trans_unit['source']['raw-content'] );

                        //we do the word count after the place-holding with <x id="nbsp"/>
                        //so &nbsp; are now not recognized as word and not counted as payable
                        $num_words = CatUtils::segment_raw_wordcount($source);

                        //applying escaping after raw count
                        $source = mysql_real_escape_string($source);

                        $trans_unit_id = mysql_real_escape_string($xliff_trans_unit['attr']['id']);

                        if (!is_null($prec_tags)) {
                            $prec_tags = mysql_real_escape_string($prec_tags);
                        }
                        if (!is_null($succ_tags)) {
                            $succ_tags = mysql_real_escape_string($succ_tags);
                        }

                        if( $this->projectStructure['file_references']->offsetExists( $fid ) ){
                            $file_reference = $this->projectStructure['file_references'][$fid];
                        } else $file_reference = null;

                        $this->projectStructure['segments'][$fid]->append( "('$trans_unit_id',$fid,'$file_reference','$source',$num_words,NULL,'$prec_tags','$succ_tags',$show_in_cattool,NULL,NULL)" );

                    }
                }
            }
        }

        // *NOTE*: PHP>=5.3 throws UnexpectedValueException, but PHP 5.2 throws ErrorException
        //use generic

        if (empty($this->projectStructure['segments'][$fid])) {
            Log::doLog("Segment import - no segments found\n");
            throw new Exception( "Segment import - no segments found", -1 );
        }

        $baseQuery = "INSERT INTO segments ( internal_id, id_file, id_file_part, segment, raw_word_count, xliff_mrk_id, xliff_ext_prec_tags, xliff_ext_succ_tags, show_in_cattool,xliff_mrk_ext_prec_tags,xliff_mrk_ext_succ_tags) values ";

        Log::doLog( "Segments: Total Rows to insert: " . count( $this->projectStructure['segments'][$fid] ) );
        //split the query in to chunks if there are too much segments
        $this->projectStructure['segments'][$fid]->exchangeArray( array_chunk( $this->projectStructure['segments'][$fid]->getArrayCopy(), 1000 ) );

        Log::doLog( "Segments: Total Queries to execute: " . count( $this->projectStructure['segments'][$fid] ) );


        foreach( $this->projectStructure['segments'][$fid] as $i => $chunk ){

            $res = mysql_query( $baseQuery . join(",\n", $chunk ) , $this->mysql_link);
            Log::doLog( "Segments: Executed Query " . ( $i+1 ) );
            if (!$res) {
                Log::doLog("Segment import - DB Error: " . mysql_error() . " - \n");
                throw new Exception( "Segment import - DB Error: " . mysql_error() . " - $chunk", -2 );
            }

        }

        //Log::doLog( $this->projectStructure );

        if( !empty( $this->projectStructure['translations'] ) ){

            $last_segments_query = "SELECT id, internal_id from segments WHERE id_file = %u";
            $last_segments_query = sprintf( $last_segments_query, $fid );

            $last_segments = mysql_query( $last_segments_query, $this->mysql_link );

            //assignment in condition is often dangerous, deprecated
            while ( ( $row = mysql_fetch_assoc( $last_segments ) ) != false ) {

                if( $this->projectStructure['translations']->offsetExists( "" . $row['internal_id'] ) ) {
                    $this->projectStructure['translations'][ "" . $row['internal_id'] ]->offsetSet( 0, $row['id'] );
                    $this->projectStructure['translations'][ "" . $row['internal_id'] ]->offsetSet( 1, $row['internal_id'] );
                }

            }

        }

    }

    protected function _insertPreTranslations( $jid ){

//    Log::doLog( array_shift( array_chunk( $SegmentTranslations, 5, true ) ) );

        foreach ( $this->projectStructure['translations'] as $internal_id => $struct ){

            if( empty($struct) ) {
//            Log::doLog( $internal_id . " : " . var_export( $struct, true ) );
                continue;
            }

            //id_segment, id_job, status, translation, translation_date, tm_analysis_status, locked
            $this->projectStructure['query_translations']->append( "( '{$struct[0]}', $jid, 'TRANSLATED', '{$struct[2]}', NOW(), 'DONE', 1 )" );

        }

        // Executing the Query
        if( !empty( $this->projectStructure['query_translations'] ) ){

            $baseQuery = "INSERT INTO segment_translations (id_segment, id_job, status, translation, translation_date, tm_analysis_status, locked)
            values ";

            Log::doLog( "Pre-Translations: Total Rows to insert: " . count( $this->projectStructure['query_translations'] ) );
            //split the query in to chunks if there are too much segments
            $this->projectStructure['query_translations']->exchangeArray( array_chunk( $this->projectStructure['query_translations']->getArrayCopy(), 1000 ) );

            Log::doLog( "Pre-Translations: Total Queries to execute: " . count( $this->projectStructure['query_translations'] ) );

//        Log::doLog( print_r( $query_translations,true ) );

            foreach( $this->projectStructure['query_translations'] as $i => $chunk ){

                $res = mysql_query( $baseQuery . join(",\n", $chunk ) , $this->mysql_link);
                Log::doLog( "Pre-Translations: Executed Query " . ( $i+1 ) );
                if (!$res) {
                    Log::doLog("Segment import - DB Error: " . mysql_error() . " - \n");
                    throw new Exception( "Translations Segment import - DB Error: " . mysql_error() . " - $chunk", -2 );
                }

            }

        }

        //clean translations and queries
        $this->projectStructure['query_translations']->exchangeArray( array() );
        $this->projectStructure['translations']->exchangeArray( array() );

    }

    protected function _generatePassword( $length = 12 ){
        return CatUtils::generate_password( $length );
    }

    protected function _strip_external( $a ) {
        $a               = str_replace( "\n", " NL ", $a );
        $pattern_x_start = '/^(\s*<x .*?\/>)(.*)/mis';
        $pattern_x_end   = '/(.*)(<x .*?\/>\s*)$/mis';
        $pattern_g       = '/^(\s*<g [^>]*?>)([^<]*?)(<\/g>\s*)$/mis';
        $found           = false;
        $prec            = "";
        $succ            = "";

        $c = 0;

        do {
            $c += 1;
            $found = false;

            do {
                $r = preg_match_all( $pattern_x_start, $a, $res );
                if ( isset( $res[ 1 ][ 0 ] ) ) {
                    $prec .= $res[ 1 ][ 0 ];
                    $a     = $res[ 2 ][ 0 ];
                    $found = true;
                }
            } while ( isset( $res[ 1 ][ 0 ] ) );

            do {
                $r = preg_match_all( $pattern_x_end, $a, $res );
                if ( isset( $res[ 2 ][ 0 ] ) ) {
                    $succ  = $res[ 2 ][ 0 ] . $succ;
                    $a     = $res[ 1 ][ 0 ];
                    $found = true;
                }
            } while ( isset( $res[ 2 ][ 0 ] ) );

            do {
                $r = preg_match_all( $pattern_g, $a, $res );
                if ( isset( $res[ 1 ][ 0 ] ) ) {
                    $prec .= $res[ 1 ][ 0 ];
                    $succ  = $res[ 3 ][ 0 ] . $succ;
                    $a     = $res[ 2 ][ 0 ];
                    $found = true;
                }
            } while ( isset( $res[ 1 ][ 0 ] ) );

        } while ( $found );
        $prec = str_replace( " NL ", "\n", $prec );
        $succ = str_replace( " NL ", "\n", $succ );
        $a    = str_replace( " NL ", "\n", $a );
        $r    = array( 'prec' => $prec, 'seg' => $a, 'succ' => $succ );

        return $r;
    }
    
    protected static function _deleteDir( $dirPath ) {
        return true;
        $iterator = new DirectoryIterator( $dirPath );

        foreach ( $iterator as $fileInfo ) {
            if ( $fileInfo->isDot() ) continue;
            if ( $fileInfo->isDir() ) {
                self::_deleteDir( $fileInfo->getPathname() );
            } else {
                unlink( $fileInfo->getPathname() );
            }
        }
        rmdir( $iterator->getPath() );

    }

    protected function _getExtensionFromMimeType( $mime_type ){

        $reference = array(
            'application/andrew-inset'         =>
                    array(
                        0 => 'ez',
                    ),
            'application/applixware'           =>
                    array(
                        0 => 'aw',
                    ),
            'application/atom+xml'             =>
                    array(
                        0 => 'atom',
                    ),
            'application/atomcat+xml'          =>
                    array(
                        0 => 'atomcat',
                    ),
            'application/atomsvc+xml'          =>
                    array(
                        0 => 'atomsvc',
                    ),
            'application/ccxml+xml'            =>
                    array(
                        0 => 'ccxml',
                    ),
            'application/cdmi-capability'      =>
                    array(
                        0 => 'cdmia',
                    ),
            'application/cdmi-container'       =>
                    array(
                        0 => 'cdmic',
                    ),
            'application/cdmi-domain'          =>
                    array(
                        0 => 'cdmid',
                    ),
            'application/cdmi-object'          =>
                    array(
                        0 => 'cdmio',
                    ),
            'application/cdmi-queue'           =>
                    array(
                        0 => 'cdmiq',
                    ),
            'application/cu-seeme'             =>
                    array(
                        0 => 'cu',
                    ),
            'application/davmount+xml'         =>
                    array(
                        0 => 'davmount',
                    ),
            'application/docbook+xml'          =>
                    array(
                        0 => 'dbk',
                    ),
            'application/dssc+der'             =>
                    array(
                        0 => 'dssc',
                    ),
            'application/dssc+xml'             =>
                    array(
                        0 => 'xdssc',
                    ),
//            'application/ecmascript'           =>
//                    array(
//                        0 => 'ecma',
//                    ),
            'application/emma+xml'             =>
                    array(
                        0 => 'emma',
                    ),
            'application/epub+zip'             =>
                    array(
                        0 => 'epub',
                    ),
            'application/exi'                  =>
                    array(
                        0 => 'exi',
                    ),
            'application/font-tdpfr'           =>
                    array(
                        0 => 'pfr',
                    ),
            'application/font-woff'            =>
                    array(
                        0 => 'woff',
                    ),
            'application/gml+xml'              =>
                    array(
                        0 => 'gml',
                    ),
            'application/gpx+xml'              =>
                    array(
                        0 => 'gpx',
                    ),
            'application/gxf'                  =>
                    array(
                        0 => 'gxf',
                    ),
            'application/hyperstudio'          =>
                    array(
                        0 => 'stk',
                    ),
            'application/inkml+xml'            =>
                    array(
                        0 => 'ink',
                        1 => 'inkml',
                    ),
            'application/ipfix'                =>
                    array(
                        0 => 'ipfix',
                    ),
            'application/java-archive'         =>
                    array(
                        0 => 'jar',
                    ),
            'application/java-serialized-object'   =>
                    array(
                        0 => 'ser',
                    ),
            'application/java-vm'              =>
                    array(
                        0 => 'class',
                    ),
//            'application/javascript'           =>
//                    array(
//                        0 => 'js',
//                    ),
            'application/json'                 =>
                    array(
                        0 => 'json',
                    ),
            'application/jsonml+json'          =>
                    array(
                        0 => 'jsonml',
                    ),
            'application/lost+xml'             =>
                    array(
                        0 => 'lostxml',
                    ),
            'application/mac-binhex40'         =>
                    array(
                        0 => 'hqx',
                    ),
            'application/mac-compactpro'       =>
                    array(
                        0 => 'cpt',
                    ),
            'application/mads+xml'             =>
                    array(
                        0 => 'mads',
                    ),
            'application/marc'                 =>
                    array(
                        0 => 'mrc',
                    ),
            'application/marcxml+xml'          =>
                    array(
                        0 => 'mrcx',
                    ),
            'application/mathematica'          =>
                    array(
                        0 => 'ma',
                        1 => 'nb',
                        2 => 'mb',
                    ),
            'application/mathml+xml'           =>
                    array(
                        0 => 'mathml',
                    ),
            'application/mbox'                 =>
                    array(
                        0 => 'mbox',
                    ),
            'application/mediaservercontrol+xml'   =>
                    array(
                        0 => 'mscml',
                    ),
            'application/metalink+xml'         =>
                    array(
                        0 => 'metalink',
                    ),
            'application/metalink4+xml'        =>
                    array(
                        0 => 'meta4',
                    ),
            'application/mets+xml'             =>
                    array(
                        0 => 'mets',
                    ),
            'application/mods+xml'             =>
                    array(
                        0 => 'mods',
                    ),
            'application/mp21'                 =>
                    array(
                        0 => 'm21',
                        1 => 'mp21',
                    ),
            'application/mp4'                  =>
                    array(
                        0 => 'mp4s',
                    ),
            'application/msword'               =>
                    array(
                        0 => 'doc',
                        1 => 'dot',
                    ),
            'application/mxf'                  =>
                    array(
                        0 => 'mxf',
                    ),
            'application/octet-stream'         =>
                    array(
                        'default' => 'bin',
                        0  => 'bin',
                        1  => 'dms',
                        2  => 'lrf',
                        3  => 'mar',
                        4  => 'so',
                        5  => 'dist',
                        6  => 'distz',
                        7  => 'pkg',
                        8  => 'bpk',
                        9  => 'dump',
                        10 => 'elc',
                        11 => 'deploy',
                    ),
            'application/oda'                  =>
                    array(
                        0 => 'oda',
                    ),
            'application/oebps-package+xml'    =>
                    array(
                        0 => 'opf',
                    ),
            'application/ogg'                  =>
                    array(
                        0 => 'ogx',
                    ),
            'application/omdoc+xml'            =>
                    array(
                        0 => 'omdoc',
                    ),
            'application/onenote'              =>
                    array(
                        0 => 'onetoc',
                        1 => 'onetoc2',
                        2 => 'onetmp',
                        3 => 'onepkg',
                    ),
            'application/oxps'                 =>
                    array(
                        0 => 'oxps',
                    ),
            'application/patch-ops-error+xml'  =>
                    array(
                        0 => 'xer',
                    ),
            'application/pdf'                  =>
                    array(
                        0 => 'pdf',
                    ),
            'application/pgp-encrypted'        =>
                    array(
                        0 => 'pgp',
                    ),
            'application/pgp-signature'        =>
                    array(
                        0 => 'asc',
                        1 => 'sig',
                    ),
            'application/pics-rules'           =>
                    array(
                        0 => 'prf',
                    ),
            'application/pkcs10'               =>
                    array(
                        0 => 'p10',
                    ),
            'application/pkcs7-mime'           =>
                    array(
                        0 => 'p7m',
                        1 => 'p7c',
                    ),
            'application/pkcs7-signature'      =>
                    array(
                        0 => 'p7s',
                    ),
            'application/pkcs8'                =>
                    array(
                        0 => 'p8',
                    ),
            'application/pkix-attr-cert'       =>
                    array(
                        0 => 'ac',
                    ),
            'application/pkix-cert'            =>
                    array(
                        0 => 'cer',
                    ),
            'application/pkix-crl'             =>
                    array(
                        0 => 'crl',
                    ),
            'application/pkix-pkipath'         =>
                    array(
                        0 => 'pkipath',
                    ),
            'application/pkixcmp'              =>
                    array(
                        0 => 'pki',
                    ),
            'application/pls+xml'              =>
                    array(
                        0 => 'pls',
                    ),
            'application/postscript'           =>
                    array(
                        'default' => 'ps',
                        0 => 'ai',
                        1 => 'eps',
                        2 => 'ps',
                    ),
            'application/prs.cww'              =>
                    array(
                        0 => 'cww',
                    ),
            'application/pskc+xml'             =>
                    array(
                        0 => 'pskcxml',
                    ),
            'application/rdf+xml'              =>
                    array(
                        0 => 'rdf',
                    ),
            'application/reginfo+xml'          =>
                    array(
                        0 => 'rif',
                    ),
            'application/relax-ng-compact-syntax'  =>
                    array(
                        0 => 'rnc',
                    ),
            'application/resource-lists+xml'   =>
                    array(
                        0 => 'rl',
                    ),
            'application/resource-lists-diff+xml'  =>
                    array(
                        0 => 'rld',
                    ),
            'application/rls-services+xml'     =>
                    array(
                        0 => 'rs',
                    ),
            'application/rpki-ghostbusters'    =>
                    array(
                        0 => 'gbr',
                    ),
            'application/rpki-manifest'        =>
                    array(
                        0 => 'mft',
                    ),
            'application/rpki-roa'             =>
                    array(
                        0 => 'roa',
                    ),
            'application/rsd+xml'              =>
                    array(
                        0 => 'rsd',
                    ),
            'application/rss+xml'              =>
                    array(
                        0 => 'rss',
                    ),
            'application/rtf'                  =>
                    array(
                        0 => 'rtf',
                    ),
            'application/sbml+xml'             =>
                    array(
                        0 => 'sbml',
                    ),
            'application/scvp-cv-request'      =>
                    array(
                        0 => 'scq',
                    ),
            'application/scvp-cv-response'     =>
                    array(
                        0 => 'scs',
                    ),
            'application/scvp-vp-request'      =>
                    array(
                        0 => 'spq',
                    ),
            'application/scvp-vp-response'     =>
                    array(
                        0 => 'spp',
                    ),
            'application/sdp'                  =>
                    array(
                        0 => 'sdp',
                    ),
            'application/set-payment-initiation'   =>
                    array(
                        0 => 'setpay',
                    ),
            'application/set-registration-initiation'    =>
                    array(
                        0 => 'setreg',
                    ),
            'application/shf+xml'              =>
                    array(
                        0 => 'shf',
                    ),
            'application/smil+xml'             =>
                    array(
                        0 => 'smi',
                        1 => 'smil',
                    ),
            'application/sparql-query'         =>
                    array(
                        0 => 'rq',
                    ),
            'application/sparql-results+xml'   =>
                    array(
                        0 => 'srx',
                    ),
            'application/srgs'                 =>
                    array(
                        0 => 'gram',
                    ),
            'application/srgs+xml'             =>
                    array(
                        0 => 'grxml',
                    ),
            'application/sru+xml'              =>
                    array(
                        0 => 'sru',
                    ),
            'application/ssdl+xml'             =>
                    array(
                        0 => 'ssdl',
                    ),
            'application/ssml+xml'             =>
                    array(
                        0 => 'ssml',
                    ),
            'application/tei+xml'              =>
                    array(
                        0 => 'tei',
                        1 => 'teicorpus',
                    ),
            'application/thraud+xml'           =>
                    array(
                        0 => 'tfi',
                    ),
            'application/timestamped-data'     =>
                    array(
                        0 => 'tsd',
                    ),
            'application/vnd.3gpp.pic-bw-large'=>
                    array(
                        0 => 'plb',
                    ),
            'application/vnd.3gpp.pic-bw-small'=>
                    array(
                        0 => 'psb',
                    ),
            'application/vnd.3gpp.pic-bw-var'  =>
                    array(
                        0 => 'pvb',
                    ),
            'application/vnd.3gpp2.tcap'       =>
                    array(
                        0 => 'tcap',
                    ),
            'application/vnd.3m.post-it-notes' =>
                    array(
                        0 => 'pwn',
                    ),
            'application/vnd.accpac.simply.aso'=>
                    array(
                        0 => 'aso',
                    ),
            'application/vnd.accpac.simply.imp'=>
                    array(
                        0 => 'imp',
                    ),
            'application/vnd.acucobol'         =>
                    array(
                        0 => 'acu',
                    ),
            'application/vnd.acucorp'          =>
                    array(
                        0 => 'atc',
                        1 => 'acutc',
                    ),
            'application/vnd.adobe.air-application-installer-package+zip'               =>
                    array(
                        0 => 'air',
                    ),
            'application/vnd.adobe.formscentral.fcdt'    =>
                    array(
                        0 => 'fcdt',
                    ),
            'application/vnd.adobe.fxp'        =>
                    array(
                        0 => 'fxp',
                        1 => 'fxpl',
                    ),
            'application/vnd.adobe.xdp+xml'    =>
                    array(
                        0 => 'xdp',
                    ),
            'application/vnd.adobe.xfdf'       =>
                    array(
                        0 => 'xfdf',
                    ),
            'application/vnd.ahead.space'      =>
                    array(
                        0 => 'ahead',
                    ),
            'application/vnd.airzip.filesecure.azf'=>
                    array(
                        0 => 'azf',
                    ),
            'application/vnd.airzip.filesecure.azs'=>
                    array(
                        0 => 'azs',
                    ),
            'application/vnd.amazon.ebook'     =>
                    array(
                        0 => 'azw',
                    ),
            'application/vnd.americandynamics.acc' =>
                    array(
                        0 => 'acc',
                    ),
            'application/vnd.amiga.ami'        =>
                    array(
                        0 => 'ami',
                    ),
            'application/vnd.android.package-archive'    =>
                    array(
                        0 => 'apk',
                    ),
            'application/vnd.anser-web-certificate-issue-initiation'                    =>
                    array(
                        0 => 'cii',
                    ),
            'application/vnd.anser-web-funds-transfer-initiation'                       =>
                    array(
                        0 => 'fti',
                    ),
            'application/vnd.antix.game-component' =>
                    array(
                        0 => 'atx',
                    ),
            'application/vnd.apple.installer+xml'  =>
                    array(
                        0 => 'mpkg',
                    ),
            'application/vnd.apple.mpegurl'    =>
                    array(
                        0 => 'm3u8',
                    ),
            'application/vnd.aristanetworks.swi'   =>
                    array(
                        0 => 'swi',
                    ),
            'application/vnd.astraea-software.iota'=>
                    array(
                        0 => 'iota',
                    ),
            'application/vnd.audiograph'       =>
                    array(
                        0 => 'aep',
                    ),
            'application/vnd.blueice.multipass'=>
                    array(
                        0 => 'mpm',
                    ),
            'application/vnd.bmi'              =>
                    array(
                        0 => 'bmi',
                    ),
            'application/vnd.businessobjects'  =>
                    array(
                        0 => 'rep',
                    ),
            'application/vnd.chemdraw+xml'     =>
                    array(
                        0 => 'cdxml',
                    ),
            'application/vnd.chipnuts.karaoke-mmd' =>
                    array(
                        0 => 'mmd',
                    ),
            'application/vnd.cinderella'       =>
                    array(
                        0 => 'cdy',
                    ),
            'application/vnd.claymore'         =>
                    array(
                        0 => 'cla',
                    ),
            'application/vnd.cloanto.rp9'      =>
                    array(
                        0 => 'rp9',
                    ),
            'application/vnd.clonk.c4group'    =>
                    array(
                        0 => 'c4g',
                        1 => 'c4d',
                        2 => 'c4f',
                        3 => 'c4p',
                        4 => 'c4u',
                    ),
            'application/vnd.cluetrust.cartomobile-config'                              =>
                    array(
                        0 => 'c11amc',
                    ),
            'application/vnd.cluetrust.cartomobile-config-pkg'                          =>
                    array(
                        0 => 'c11amz',
                    ),
            'application/vnd.commonspace'      =>
                    array(
                        0 => 'csp',
                    ),
            'application/vnd.contact.cmsg'     =>
                    array(
                        0 => 'cdbcmsg',
                    ),
            'application/vnd.cosmocaller'      =>
                    array(
                        0 => 'cmc',
                    ),
            'application/vnd.crick.clicker'    =>
                    array(
                        0 => 'clkx',
                    ),
            'application/vnd.crick.clicker.keyboard'     =>
                    array(
                        0 => 'clkk',
                    ),
            'application/vnd.crick.clicker.palette'=>
                    array(
                        0 => 'clkp',
                    ),
            'application/vnd.crick.clicker.template'     =>
                    array(
                        0 => 'clkt',
                    ),
            'application/vnd.crick.clicker.wordbank'     =>
                    array(
                        0 => 'clkw',
                    ),
            'application/vnd.criticaltools.wbs+xml'=>
                    array(
                        0 => 'wbs',
                    ),
            'application/vnd.ctc-posml'        =>
                    array(
                        0 => 'pml',
                    ),
            'application/vnd.cups-ppd'         =>
                    array(
                        0 => 'ppd',
                    ),
            'application/vnd.curl.car'         =>
                    array(
                        0 => 'car',
                    ),
            'application/vnd.curl.pcurl'       =>
                    array(
                        0 => 'pcurl',
                    ),
            'application/vnd.dart'             =>
                    array(
                        0 => 'dart',
                    ),
            'application/vnd.data-vision.rdz'  =>
                    array(
                        0 => 'rdz',
                    ),
            'application/vnd.dece.data'        =>
                    array(
                        0 => 'uvf',
                        1 => 'uvvf',
                        2 => 'uvd',
                        3 => 'uvvd',
                    ),
            'application/vnd.dece.ttml+xml'    =>
                    array(
                        0 => 'uvt',
                        1 => 'uvvt',
                    ),
            'application/vnd.dece.unspecified' =>
                    array(
                        0 => 'uvx',
                        1 => 'uvvx',
                    ),
            'application/vnd.dece.zip'         =>
                    array(
                        0 => 'uvz',
                        1 => 'uvvz',
                    ),
            'application/vnd.denovo.fcselayout-link'     =>
                    array(
                        0 => 'fe_launch',
                    ),
            'application/vnd.dna'              =>
                    array(
                        0 => 'dna',
                    ),
            'application/vnd.dolby.mlp'        =>
                    array(
                        0 => 'mlp',
                    ),
            'application/vnd.dpgraph'          =>
                    array(
                        0 => 'dpg',
                    ),
            'application/vnd.dreamfactory'     =>
                    array(
                        0 => 'dfac',
                    ),
            'application/vnd.ds-keypoint'      =>
                    array(
                        0 => 'kpxx',
                    ),
            'application/vnd.dvb.ait'          =>
                    array(
                        0 => 'ait',
                    ),
            'application/vnd.dvb.service'      =>
                    array(
                        0 => 'svc',
                    ),
            'application/vnd.dynageo'          =>
                    array(
                        0 => 'geo',
                    ),
            'application/vnd.ecowin.chart'     =>
                    array(
                        0 => 'mag',
                    ),
            'application/vnd.enliven'          =>
                    array(
                        0 => 'nml',
                    ),
            'application/vnd.epson.esf'        =>
                    array(
                        0 => 'esf',
                    ),
            'application/vnd.epson.msf'        =>
                    array(
                        0 => 'msf',
                    ),
            'application/vnd.epson.quickanime' =>
                    array(
                        0 => 'qam',
                    ),
            'application/vnd.epson.salt'       =>
                    array(
                        0 => 'slt',
                    ),
            'application/vnd.epson.ssf'        =>
                    array(
                        0 => 'ssf',
                    ),
            'application/vnd.eszigno3+xml'     =>
                    array(
                        0 => 'es3',
                        1 => 'et3',
                    ),
            'application/vnd.ezpix-album'      =>
                    array(
                        0 => 'ez2',
                    ),
            'application/vnd.ezpix-package'    =>
                    array(
                        0 => 'ez3',
                    ),
            'application/vnd.fdf'              =>
                    array(
                        0 => 'fdf',
                    ),
            'application/vnd.fdsn.mseed'       =>
                    array(
                        0 => 'mseed',
                    ),
            'application/vnd.fdsn.seed'        =>
                    array(
                        0 => 'seed',
                        1 => 'dataless',
                    ),
            'application/vnd.flographit'       =>
                    array(
                        0 => 'gph',
                    ),
            'application/vnd.fluxtime.clip'    =>
                    array(
                        0 => 'ftc',
                    ),
            'application/vnd.framemaker'       =>
                    array(
                        0 => 'fm',
                        1 => 'frame',
                        2 => 'maker',
                        3 => 'book',
                    ),
            'application/vnd.frogans.fnc'      =>
                    array(
                        0 => 'fnc',
                    ),
            'application/vnd.frogans.ltf'      =>
                    array(
                        0 => 'ltf',
                    ),
            'application/vnd.fsc.weblaunch'    =>
                    array(
                        0 => 'fsc',
                    ),
            'application/vnd.fujitsu.oasys'    =>
                    array(
                        0 => 'oas',
                    ),
            'application/vnd.fujitsu.oasys2'   =>
                    array(
                        0 => 'oa2',
                    ),
            'application/vnd.fujitsu.oasys3'   =>
                    array(
                        0 => 'oa3',
                    ),
            'application/vnd.fujitsu.oasysgp'  =>
                    array(
                        0 => 'fg5',
                    ),
            'application/vnd.fujitsu.oasysprs' =>
                    array(
                        0 => 'bh2',
                    ),
            'application/vnd.fujixerox.ddd'    =>
                    array(
                        0 => 'ddd',
                    ),
            'application/vnd.fujixerox.docuworks'  =>
                    array(
                        0 => 'xdw',
                    ),
            'application/vnd.fujixerox.docuworks.binder' =>
                    array(
                        0 => 'xbd',
                    ),
            'application/vnd.fuzzysheet'       =>
                    array(
                        0 => 'fzs',
                    ),
            'application/vnd.genomatix.tuxedo' =>
                    array(
                        0 => 'txd',
                    ),
            'application/vnd.geogebra.file'    =>
                    array(
                        0 => 'ggb',
                    ),
            'application/vnd.geogebra.tool'    =>
                    array(
                        0 => 'ggt',
                    ),
            'application/vnd.geometry-explorer'=>
                    array(
                        0 => 'gex',
                        1 => 'gre',
                    ),
            'application/vnd.geonext'          =>
                    array(
                        0 => 'gxt',
                    ),
            'application/vnd.geoplan'          =>
                    array(
                        0 => 'g2w',
                    ),
            'application/vnd.geospace'         =>
                    array(
                        0 => 'g3w',
                    ),
            'application/vnd.gmx'              =>
                    array(
                        0 => 'gmx',
                    ),
            'application/vnd.google-earth.kml+xml' =>
                    array(
                        0 => 'kml',
                    ),
            'application/vnd.google-earth.kmz' =>
                    array(
                        0 => 'kmz',
                    ),
            'application/vnd.grafeq'           =>
                    array(
                        0 => 'gqf',
                        1 => 'gqs',
                    ),
            'application/vnd.groove-account'   =>
                    array(
                        0 => 'gac',
                    ),
            'application/vnd.groove-help'      =>
                    array(
                        0 => 'ghf',
                    ),
            'application/vnd.groove-identity-message'    =>
                    array(
                        0 => 'gim',
                    ),
            'application/vnd.groove-injector'  =>
                    array(
                        0 => 'grv',
                    ),
            'application/vnd.groove-tool-message'  =>
                    array(
                        0 => 'gtm',
                    ),
            'application/vnd.groove-tool-template' =>
                    array(
                        0 => 'tpl',
                    ),
            'application/vnd.groove-vcard'     =>
                    array(
                        0 => 'vcg',
                    ),
            'application/vnd.hal+xml'          =>
                    array(
                        0 => 'hal',
                    ),
            'application/vnd.handheld-entertainment+xml' =>
                    array(
                        0 => 'zmm',
                    ),
            'application/vnd.hbci'             =>
                    array(
                        0 => 'hbci',
                    ),
            'application/vnd.hhe.lesson-player'=>
                    array(
                        0 => 'les',
                    ),
            'application/vnd.hp-hpgl'          =>
                    array(
                        0 => 'hpgl',
                    ),
            'application/vnd.hp-hpid'          =>
                    array(
                        0 => 'hpid',
                    ),
            'application/vnd.hp-hps'           =>
                    array(
                        0 => 'hps',
                    ),
            'application/vnd.hp-jlyt'          =>
                    array(
                        0 => 'jlt',
                    ),
            'application/vnd.hp-pcl'           =>
                    array(
                        0 => 'pcl',
                    ),
            'application/vnd.hp-pclxl'         =>
                    array(
                        0 => 'pclxl',
                    ),
            'application/vnd.hydrostatix.sof-data' =>
                    array(
                        0 => 'sfd-hdstx',
                    ),
            'application/vnd.ibm.minipay'      =>
                    array(
                        0 => 'mpy',
                    ),
            'application/vnd.ibm.modcap'       =>
                    array(
                        0 => 'afp',
                        1 => 'listafp',
                        2 => 'list3820',
                    ),
            'application/vnd.ibm.rights-management'=>
                    array(
                        0 => 'irm',
                    ),
            'application/vnd.ibm.secure-container' =>
                    array(
                        0 => 'sc',
                    ),
            'application/vnd.iccprofile'       =>
                    array(
                        0 => 'icc',
                        1 => 'icm',
                    ),
            'application/vnd.igloader'         =>
                    array(
                        0 => 'igl',
                    ),
            'application/vnd.immervision-ivp'  =>
                    array(
                        0 => 'ivp',
                    ),
            'application/vnd.immervision-ivu'  =>
                    array(
                        0 => 'ivu',
                    ),
            'application/vnd.insors.igm'       =>
                    array(
                        0 => 'igm',
                    ),
            'application/vnd.intercon.formnet' =>
                    array(
                        0 => 'xpw',
                        1 => 'xpx',
                    ),
            'application/vnd.intergeo'         =>
                    array(
                        0 => 'i2g',
                    ),
            'application/vnd.intu.qbo'         =>
                    array(
                        0 => 'qbo',
                    ),
            'application/vnd.intu.qfx'         =>
                    array(
                        0 => 'qfx',
                    ),
            'application/vnd.ipunplugged.rcprofile'=>
                    array(
                        0 => 'rcprofile',
                    ),
            'application/vnd.irepository.package+xml'    =>
                    array(
                        0 => 'irp',
                    ),
            'application/vnd.is-xpr'           =>
                    array(
                        0 => 'xpr',
                    ),
            'application/vnd.isac.fcs'         =>
                    array(
                        0 => 'fcs',
                    ),
            'application/vnd.jam'              =>
                    array(
                        0 => 'jam',
                    ),
            'application/vnd.jcp.javame.midlet-rms'=>
                    array(
                        0 => 'rms',
                    ),
            'application/vnd.jisp'             =>
                    array(
                        0 => 'jisp',
                    ),
            'application/vnd.joost.joda-archive'   =>
                    array(
                        0 => 'joda',
                    ),
            'application/vnd.kahootz'          =>
                    array(
                        0 => 'ktz',
                        1 => 'ktr',
                    ),
            'application/vnd.kde.karbon'       =>
                    array(
                        0 => 'karbon',
                    ),
            'application/vnd.kde.kchart'       =>
                    array(
                        0 => 'chrt',
                    ),
            'application/vnd.kde.kformula'     =>
                    array(
                        0 => 'kfo',
                    ),
            'application/vnd.kde.kivio'        =>
                    array(
                        0 => 'flw',
                    ),
            'application/vnd.kde.kontour'      =>
                    array(
                        0 => 'kon',
                    ),
            'application/vnd.kde.kpresenter'   =>
                    array(
                        0 => 'kpr',
                        1 => 'kpt',
                    ),
            'application/vnd.kde.kspread'      =>
                    array(
                        0 => 'ksp',
                    ),
            'application/vnd.kde.kword'        =>
                    array(
                        0 => 'kwd',
                        1 => 'kwt',
                    ),
            'application/vnd.kenameaapp'       =>
                    array(
                        0 => 'htke',
                    ),
            'application/vnd.kidspiration'     =>
                    array(
                        0 => 'kia',
                    ),
            'application/vnd.kinar'            =>
                    array(
                        0 => 'kne',
                        1 => 'knp',
                    ),
            'application/vnd.koan'             =>
                    array(
                        0 => 'skp',
                        1 => 'skd',
                        2 => 'skt',
                        3 => 'skm',
                    ),
            'application/vnd.kodak-descriptor' =>
                    array(
                        0 => 'sse',
                    ),
            'application/vnd.las.las+xml'      =>
                    array(
                        0 => 'lasxml',
                    ),
            'application/vnd.llamagraphics.life-balance.desktop'                        =>
                    array(
                        0 => 'lbd',
                    ),
            'application/vnd.llamagraphics.life-balance.exchange+xml'                   =>
                    array(
                        0 => 'lbe',
                    ),
            'application/vnd.lotus-1-2-3'      =>
                    array(
                        0 => '123',
                    ),
            'application/vnd.lotus-approach'   =>
                    array(
                        0 => 'apr',
                    ),
            'application/vnd.lotus-freelance'  =>
                    array(
                        0 => 'pre',
                    ),
            'application/vnd.lotus-notes'      =>
                    array(
                        0 => 'nsf',
                    ),
            'application/vnd.lotus-organizer'  =>
                    array(
                        0 => 'org',
                    ),
            'application/vnd.lotus-screencam'  =>
                    array(
                        0 => 'scm',
                    ),
            'application/vnd.lotus-wordpro'    =>
                    array(
                        0 => 'lwp',
                    ),
            'application/vnd.macports.portpkg' =>
                    array(
                        0 => 'portpkg',
                    ),
            'application/vnd.mcd'              =>
                    array(
                        0 => 'mcd',
                    ),
            'application/vnd.medcalcdata'      =>
                    array(
                        0 => 'mc1',
                    ),
            'application/vnd.mediastation.cdkey'   =>
                    array(
                        0 => 'cdkey',
                    ),
            'application/vnd.mfer'             =>
                    array(
                        0 => 'mwf',
                    ),
            'application/vnd.mfmp'             =>
                    array(
                        0 => 'mfm',
                    ),
            'application/vnd.micrografx.flo'   =>
                    array(
                        0 => 'flo',
                    ),
            'application/vnd.micrografx.igx'   =>
                    array(
                        0 => 'igx',
                    ),
            'application/vnd.mif'              =>
                    array(
                        0 => 'mif',
                    ),
            'application/vnd.mobius.daf'       =>
                    array(
                        0 => 'daf',
                    ),
            'application/vnd.mobius.dis'       =>
                    array(
                        0 => 'dis',
                    ),
            'application/vnd.mobius.mbk'       =>
                    array(
                        0 => 'mbk',
                    ),
            'application/vnd.mobius.mqy'       =>
                    array(
                        0 => 'mqy',
                    ),
            'application/vnd.mobius.msl'       =>
                    array(
                        0 => 'msl',
                    ),
            'application/vnd.mobius.plc'       =>
                    array(
                        0 => 'plc',
                    ),
            'application/vnd.mobius.txf'       =>
                    array(
                        0 => 'txf',
                    ),
            'application/vnd.mophun.application'   =>
                    array(
                        0 => 'mpn',
                    ),
            'application/vnd.mophun.certificate'   =>
                    array(
                        0 => 'mpc',
                    ),
            'application/vnd.mozilla.xul+xml'  =>
                    array(
                        0 => 'xul',
                    ),
            'application/vnd.ms-artgalry'      =>
                    array(
                        0 => 'cil',
                    ),
            'application/vnd.ms-cab-compressed'=>
                    array(
                        0 => 'cab',
                    ),
            'application/vnd.ms-excel'         =>
                    array(
                        'default' => 'xls',
                        0 => 'xls',
                        1 => 'xlm',
                        2 => 'xla',
                        3 => 'xlc',
                        4 => 'xlt',
                        5 => 'xlw',
                    ),
            'application/vnd.ms-excel.addin.macroenabled.12'                            =>
                    array(
                        0 => 'xlam',
                    ),
            'application/vnd.ms-excel.sheet.binary.macroenabled.12'                     =>
                    array(
                        0 => 'xlsb',
                    ),
            'application/vnd.ms-excel.sheet.macroenabled.12'                            =>
                    array(
                        0 => 'xlsm',
                    ),
            'application/vnd.ms-excel.template.macroenabled.12'                         =>
                    array(
                        0 => 'xltm',
                    ),
            'application/vnd.ms-fontobject'    =>
                    array(
                        0 => 'eot',
                    ),
            'application/vnd.ms-htmlhelp'      =>
                    array(
                        0 => 'chm',
                    ),
            'application/vnd.ms-ims'           =>
                    array(
                        0 => 'ims',
                    ),
            'application/vnd.ms-lrm'           =>
                    array(
                        0 => 'lrm',
                    ),
            'application/vnd.ms-officetheme'   =>
                    array(
                        0 => 'thmx',
                    ),
            'application/vnd.ms-pki.seccat'    =>
                    array(
                        0 => 'cat',
                    ),
            'application/vnd.ms-pki.stl'       =>
                    array(
                        0 => 'stl',
                    ),
            'application/vnd.ms-powerpoint'    =>
                    array(
                        'default' => 'ppt',
                        0 => 'ppt',
                        1 => 'pps',
                        2 => 'pot',
                    ),
            'application/vnd.ms-powerpoint.addin.macroenabled.12'                       =>
                    array(
                        0 => 'ppam',
                    ),
            'application/vnd.ms-powerpoint.presentation.macroenabled.12'                =>
                    array(
                        0 => 'pptm',
                    ),
            'application/vnd.ms-powerpoint.slide.macroenabled.12'                       =>
                    array(
                        0 => 'sldm',
                    ),
            'application/vnd.ms-powerpoint.slideshow.macroenabled.12'                   =>
                    array(
                        0 => 'ppsm',
                    ),
            'application/vnd.ms-powerpoint.template.macroenabled.12'                    =>
                    array(
                        0 => 'potm',
                    ),
            'application/vnd.ms-project'       =>
                    array(
                        0 => 'mpp',
                        1 => 'mpt',
                    ),
            'application/vnd.ms-word.document.macroenabled.12'                          =>
                    array(
                        0 => 'docm',
                    ),
            'application/vnd.ms-word.template.macroenabled.12'                          =>
                    array(
                        0 => 'dotm',
                    ),
            'application/vnd.ms-works'         =>
                    array(
                        0 => 'wps',
                        1 => 'wks',
                        2 => 'wcm',
                        3 => 'wdb',
                    ),
            'application/vnd.ms-wpl'           =>
                    array(
                        0 => 'wpl',
                    ),
            'application/vnd.ms-xpsdocument'   =>
                    array(
                        0 => 'xps',
                    ),
            'application/vnd.mseq'             =>
                    array(
                        0 => 'mseq',
                    ),
            'application/vnd.musician'         =>
                    array(
                        0 => 'mus',
                    ),
            'application/vnd.muvee.style'      =>
                    array(
                        0 => 'msty',
                    ),
            'application/vnd.mynfc'            =>
                    array(
                        0 => 'taglet',
                    ),
            'application/vnd.neurolanguage.nlu'=>
                    array(
                        0 => 'nlu',
                    ),
            'application/vnd.nitf'             =>
                    array(
                        0 => 'ntf',
                        1 => 'nitf',
                    ),
            'application/vnd.noblenet-directory'   =>
                    array(
                        0 => 'nnd',
                    ),
            'application/vnd.noblenet-sealer'  =>
                    array(
                        0 => 'nns',
                    ),
            'application/vnd.noblenet-web'     =>
                    array(
                        0 => 'nnw',
                    ),
            'application/vnd.nokia.n-gage.data'=>
                    array(
                        0 => 'ngdat',
                    ),
            'application/vnd.nokia.n-gage.symbian.install'                              =>
                    array(
                        0 => 'n-gage',
                    ),
            'application/vnd.nokia.radio-preset'   =>
                    array(
                        0 => 'rpst',
                    ),
            'application/vnd.nokia.radio-presets'  =>
                    array(
                        0 => 'rpss',
                    ),
            'application/vnd.novadigm.edm'     =>
                    array(
                        0 => 'edm',
                    ),
            'application/vnd.novadigm.edx'     =>
                    array(
                        0 => 'edx',
                    ),
            'application/vnd.novadigm.ext'     =>
                    array(
                        0 => 'ext',
                    ),
            'application/vnd.oasis.opendocument.chart'   =>
                    array(
                        0 => 'odc',
                    ),
            'application/vnd.oasis.opendocument.chart-template'                         =>
                    array(
                        0 => 'otc',
                    ),
            'application/vnd.oasis.opendocument.database'=>
                    array(
                        0 => 'odb',
                    ),
            'application/vnd.oasis.opendocument.formula' =>
                    array(
                        0 => 'odf',
                    ),
            'application/vnd.oasis.opendocument.formula-template'                       =>
                    array(
                        0 => 'odft',
                    ),
            'application/vnd.oasis.opendocument.graphics'=>
                    array(
                        0 => 'odg',
                    ),
            'application/vnd.oasis.opendocument.graphics-template'                      =>
                    array(
                        0 => 'otg',
                    ),
            'application/vnd.oasis.opendocument.image'   =>
                    array(
                        0 => 'odi',
                    ),
            'application/vnd.oasis.opendocument.image-template'                         =>
                    array(
                        0 => 'oti',
                    ),
            'application/vnd.oasis.opendocument.presentation'                           =>
                    array(
                        0 => 'odp',
                    ),
            'application/vnd.oasis.opendocument.presentation-template'                  =>
                    array(
                        0 => 'otp',
                    ),
            'application/vnd.oasis.opendocument.spreadsheet'                            =>
                    array(
                        0 => 'ods',
                    ),
            'application/vnd.oasis.opendocument.spreadsheet-template'                   =>
                    array(
                        0 => 'ots',
                    ),
            'application/vnd.oasis.opendocument.text'    =>
                    array(
                        0 => 'odt',
                    ),
            'application/vnd.oasis.opendocument.text-master'                            =>
                    array(
                        0 => 'odm',
                    ),
            'application/vnd.oasis.opendocument.text-template'                          =>
                    array(
                        0 => 'ott',
                    ),
            'application/vnd.oasis.opendocument.text-web'=>
                    array(
                        0 => 'oth',
                    ),
            'application/vnd.olpc-sugar'       =>
                    array(
                        0 => 'xo',
                    ),
            'application/vnd.oma.dd2+xml'      =>
                    array(
                        0 => 'dd2',
                    ),
            'application/vnd.openofficeorg.extension'    =>
                    array(
                        0 => 'oxt',
                    ),
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' =>
                    array(
                        0 => 'pptx',
                    ),
            'application/vnd.openxmlformats-officedocument.presentationml.slide'        =>
                    array(
                        0 => 'sldx',
                    ),
            'application/vnd.openxmlformats-officedocument.presentationml.slideshow'    =>
                    array(
                        0 => 'ppsx',
                    ),
            'application/vnd.openxmlformats-officedocument.presentationml.template'     =>
                    array(
                        0 => 'potx',
                    ),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         =>
                    array(
                        0 => 'xlsx',
                    ),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.template'      =>
                    array(
                        0 => 'xltx',
                    ),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   =>
                    array(
                        0 => 'docx',
                    ),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template'   =>
                    array(
                        0 => 'dotx',
                    ),
            'application/vnd.osgeo.mapguide.package'     =>
                    array(
                        0 => 'mgp',
                    ),
            'application/vnd.osgi.dp'          =>
                    array(
                        0 => 'dp',
                    ),
            'application/vnd.osgi.subsystem'   =>
                    array(
                        0 => 'esa',
                    ),
            'application/vnd.palm'             =>
                    array(
                        0 => 'pdb',
                        1 => 'pqa',
                        2 => 'oprc',
                    ),
            'application/vnd.pawaafile'        =>
                    array(
                        0 => 'paw',
                    ),
            'application/vnd.pg.format'        =>
                    array(
                        0 => 'str',
                    ),
            'application/vnd.pg.osasli'        =>
                    array(
                        0 => 'ei6',
                    ),
            'application/vnd.picsel'           =>
                    array(
                        0 => 'efif',
                    ),
            'application/vnd.pmi.widget'       =>
                    array(
                        0 => 'wg',
                    ),
            'application/vnd.pocketlearn'      =>
                    array(
                        0 => 'plf',
                    ),
            'application/vnd.powerbuilder6'    =>
                    array(
                        0 => 'pbd',
                    ),
            'application/vnd.previewsystems.box'   =>
                    array(
                        0 => 'box',
                    ),
            'application/vnd.proteus.magazine' =>
                    array(
                        0 => 'mgz',
                    ),
            'application/vnd.publishare-delta-tree'=>
                    array(
                        0 => 'qps',
                    ),
            'application/vnd.pvi.ptid1'        =>
                    array(
                        0 => 'ptid',
                    ),
            'application/vnd.quark.quarkxpress'=>
                    array(
                        0 => 'qxd',
                        1 => 'qxt',
                        2 => 'qwd',
                        3 => 'qwt',
                        4 => 'qxl',
                        5 => 'qxb',
                    ),
            'application/vnd.realvnc.bed'      =>
                    array(
                        0 => 'bed',
                    ),
            'application/vnd.recordare.musicxml'   =>
                    array(
                        0 => 'mxl',
                    ),
            'application/vnd.recordare.musicxml+xml'     =>
                    array(
                        0 => 'musicxml',
                    ),
            'application/vnd.rig.cryptonote'   =>
                    array(
                        0 => 'cryptonote',
                    ),
            'application/vnd.rim.cod'          =>
                    array(
                        0 => 'cod',
                    ),
            'application/vnd.rn-realmedia'     =>
                    array(
                        0 => 'rm',
                    ),
            'application/vnd.rn-realmedia-vbr' =>
                    array(
                        0 => 'rmvb',
                    ),
            'application/vnd.route66.link66+xml'   =>
                    array(
                        0 => 'link66',
                    ),
            'application/vnd.sailingtracker.track' =>
                    array(
                        0 => 'st',
                    ),
            'application/vnd.seemail'          =>
                    array(
                        0 => 'see',
                    ),
            'application/vnd.sema'             =>
                    array(
                        0 => 'sema',
                    ),
            'application/vnd.semd'             =>
                    array(
                        0 => 'semd',
                    ),
            'application/vnd.semf'             =>
                    array(
                        0 => 'semf',
                    ),
            'application/vnd.shana.informed.formdata'    =>
                    array(
                        0 => 'ifm',
                    ),
            'application/vnd.shana.informed.formtemplate'=>
                    array(
                        0 => 'itp',
                    ),
            'application/vnd.shana.informed.interchange' =>
                    array(
                        0 => 'iif',
                    ),
            'application/vnd.shana.informed.package'     =>
                    array(
                        0 => 'ipk',
                    ),
            'application/vnd.simtech-mindmapper'   =>
                    array(
                        0 => 'twd',
                        1 => 'twds',
                    ),
            'application/vnd.smaf'             =>
                    array(
                        0 => 'mmf',
                    ),
            'application/vnd.smart.teacher'    =>
                    array(
                        0 => 'teacher',
                    ),
            'application/vnd.solent.sdkm+xml'  =>
                    array(
                        0 => 'sdkm',
                        1 => 'sdkd',
                    ),
            'application/vnd.spotfire.dxp'     =>
                    array(
                        0 => 'dxp',
                    ),
            'application/vnd.spotfire.sfs'     =>
                    array(
                        0 => 'sfs',
                    ),
            'application/vnd.stardivision.calc'=>
                    array(
                        0 => 'sdc',
                    ),
            'application/vnd.stardivision.draw'=>
                    array(
                        0 => 'sda',
                    ),
            'application/vnd.stardivision.impress' =>
                    array(
                        0 => 'sdd',
                    ),
            'application/vnd.stardivision.math'=>
                    array(
                        0 => 'smf',
                    ),
            'application/vnd.stardivision.writer'  =>
                    array(
                        0 => 'sdw',
                        1 => 'vor',
                    ),
            'application/vnd.stardivision.writer-global' =>
                    array(
                        0 => 'sgl',
                    ),
            'application/vnd.stepmania.package'=>
                    array(
                        0 => 'smzip',
                    ),
            'application/vnd.stepmania.stepchart'  =>
                    array(
                        0 => 'sm',
                    ),
            'application/vnd.sun.xml.calc'     =>
                    array(
                        0 => 'sxc',
                    ),
            'application/vnd.sun.xml.calc.template'=>
                    array(
                        0 => 'stc',
                    ),
            'application/vnd.sun.xml.draw'     =>
                    array(
                        0 => 'sxd',
                    ),
            'application/vnd.sun.xml.draw.template'=>
                    array(
                        0 => 'std',
                    ),
            'application/vnd.sun.xml.impress'  =>
                    array(
                        0 => 'sxi',
                    ),
            'application/vnd.sun.xml.impress.template'   =>
                    array(
                        0 => 'sti',
                    ),
            'application/vnd.sun.xml.math'     =>
                    array(
                        0 => 'sxm',
                    ),
            'application/vnd.sun.xml.writer'   =>
                    array(
                        0 => 'sxw',
                    ),
            'application/vnd.sun.xml.writer.global'=>
                    array(
                        0 => 'sxg',
                    ),
            'application/vnd.sun.xml.writer.template'    =>
                    array(
                        0 => 'stw',
                    ),
            'application/vnd.sus-calendar'     =>
                    array(
                        0 => 'sus',
                        1 => 'susp',
                    ),
            'application/vnd.svd'              =>
                    array(
                        0 => 'svd',
                    ),
            'application/vnd.symbian.install'  =>
                    array(
                        0 => 'sis',
                        1 => 'sisx',
                    ),
            'application/vnd.syncml+xml'       =>
                    array(
                        0 => 'xsm',
                    ),
            'application/vnd.syncml.dm+wbxml'  =>
                    array(
                        0 => 'bdm',
                    ),
            'application/vnd.syncml.dm+xml'    =>
                    array(
                        0 => 'xdm',
                    ),
            'application/vnd.tao.intent-module-archive'  =>
                    array(
                        0 => 'tao',
                    ),
            'application/vnd.tcpdump.pcap'     =>
                    array(
                        0 => 'pcap',
                        1 => 'cap',
                        2 => 'dmp',
                    ),
            'application/vnd.tmobile-livetv'   =>
                    array(
                        0 => 'tmo',
                    ),
            'application/vnd.trid.tpt'         =>
                    array(
                        0 => 'tpt',
                    ),
            'application/vnd.triscape.mxs'     =>
                    array(
                        0 => 'mxs',
                    ),
            'application/vnd.trueapp'          =>
                    array(
                        0 => 'tra',
                    ),
            'application/vnd.ufdl'             =>
                    array(
                        0 => 'ufd',
                        1 => 'ufdl',
                    ),
            'application/vnd.uiq.theme'        =>
                    array(
                        0 => 'utz',
                    ),
            'application/vnd.umajin'           =>
                    array(
                        0 => 'umj',
                    ),
            'application/vnd.unity'            =>
                    array(
                        0 => 'unityweb',
                    ),
            'application/vnd.uoml+xml'         =>
                    array(
                        0 => 'uoml',
                    ),
            'application/vnd.vcx'              =>
                    array(
                        0 => 'vcx',
                    ),
            'application/vnd.visio'            =>
                    array(
                        0 => 'vsd',
                        1 => 'vst',
                        2 => 'vss',
                        3 => 'vsw',
                    ),
            'application/vnd.visionary'        =>
                    array(
                        0 => 'vis',
                    ),
            'application/vnd.vsf'              =>
                    array(
                        0 => 'vsf',
                    ),
            'application/vnd.wap.wbxml'        =>
                    array(
                        0 => 'wbxml',
                    ),
            'application/vnd.wap.wmlc'         =>
                    array(
                        0 => 'wmlc',
                    ),
            'application/vnd.wap.wmlscriptc'   =>
                    array(
                        0 => 'wmlsc',
                    ),
            'application/vnd.webturbo'         =>
                    array(
                        0 => 'wtb',
                    ),
            'application/vnd.wolfram.player'   =>
                    array(
                        0 => 'nbp',
                    ),
            'application/vnd.wordperfect'      =>
                    array(
                        0 => 'wpd',
                    ),
            'application/vnd.wqd'              =>
                    array(
                        0 => 'wqd',
                    ),
            'application/vnd.wt.stf'           =>
                    array(
                        0 => 'stf',
                    ),
            'application/vnd.xara'             =>
                    array(
                        0 => 'xar',
                    ),
            'application/vnd.xfdl'             =>
                    array(
                        0 => 'xfdl',
                    ),
            'application/vnd.yamaha.hv-dic'    =>
                    array(
                        0 => 'hvd',
                    ),
            'application/vnd.yamaha.hv-script' =>
                    array(
                        0 => 'hvs',
                    ),
            'application/vnd.yamaha.hv-voice'  =>
                    array(
                        0 => 'hvp',
                    ),
            'application/vnd.yamaha.openscoreformat'     =>
                    array(
                        0 => 'osf',
                    ),
            'application/vnd.yamaha.openscoreformat.osfpvg+xml'                         =>
                    array(
                        0 => 'osfpvg',
                    ),
            'application/vnd.yamaha.smaf-audio'=>
                    array(
                        0 => 'saf',
                    ),
            'application/vnd.yamaha.smaf-phrase'   =>
                    array(
                        0 => 'spf',
                    ),
            'application/vnd.yellowriver-custom-menu'    =>
                    array(
                        0 => 'cmp',
                    ),
            'application/vnd.zul'              =>
                    array(
                        0 => 'zir',
                        1 => 'zirz',
                    ),
            'application/vnd.zzazz.deck+xml'   =>
                    array(
                        0 => 'zaz',
                    ),
            'application/voicexml+xml'         =>
                    array(
                        0 => 'vxml',
                    ),
            'application/widget'               =>
                    array(
                        0 => 'wgt',
                    ),
            'application/winhlp'               =>
                    array(
                        0 => 'hlp',
                    ),
            'application/wsdl+xml'             =>
                    array(
                        0 => 'wsdl',
                    ),
            'application/wspolicy+xml'         =>
                    array(
                        0 => 'wspolicy',
                    ),
            'application/x-7z-compressed'      =>
                    array(
                        0 => '7z',
                    ),
            'application/x-abiword'            =>
                    array(
                        0 => 'abw',
                    ),
            'application/x-ace-compressed'     =>
                    array(
                        0 => 'ace',
                    ),
            'application/x-apple-diskimage'    =>
                    array(
                        0 => 'dmg',
                    ),
            'application/x-authorware-bin'     =>
                    array(
                        0 => 'aab',
                        1 => 'x32',
                        2 => 'u32',
                        3 => 'vox',
                    ),
            'application/x-authorware-map'     =>
                    array(
                        0 => 'aam',
                    ),
            'application/x-authorware-seg'     =>
                    array(
                        0 => 'aas',
                    ),
            'application/x-bcpio'              =>
                    array(
                        0 => 'bcpio',
                    ),
            'application/x-bittorrent'         =>
                    array(
                        0 => 'torrent',
                    ),
            'application/x-blorb'              =>
                    array(
                        0 => 'blb',
                        1 => 'blorb',
                    ),
            'application/x-bzip'               =>
                    array(
                        0 => 'bz',
                    ),
            'application/x-bzip2'              =>
                    array(
                        'default' => 'bz2',
                        0 => 'bz2',
                        1 => 'boz',
                    ),
            'application/x-cbr'                =>
                    array(
                        0 => 'cbr',
                        1 => 'cba',
                        2 => 'cbt',
                        3 => 'cbz',
                        4 => 'cb7',
                    ),
            'application/x-cdlink'             =>
                    array(
                        0 => 'vcd',
                    ),
            'application/x-cfs-compressed'     =>
                    array(
                        0 => 'cfs',
                    ),
            'application/x-chat'               =>
                    array(
                        0 => 'chat',
                    ),
            'application/x-chess-pgn'          =>
                    array(
                        0 => 'pgn',
                    ),
            'application/x-conference'         =>
                    array(
                        0 => 'nsc',
                    ),
            'application/x-cpio'               =>
                    array(
                        0 => 'cpio',
                    ),
//            'application/x-csh'                =>
//                    array(
//                        0 => 'csh',
//                    ),
//            'application/x-debian-package'     =>
//                    array(
//                        0 => 'deb',
//                        1 => 'udeb',
//                    ),
            'application/x-dgc-compressed'     =>
                    array(
                        0 => 'dgc',
                    ),
            'application/x-director'           =>
                    array(
                        0 => 'dir',
                        1 => 'dcr',
                        2 => 'dxr',
                        3 => 'cst',
                        4 => 'cct',
                        5 => 'cxt',
                        6 => 'w3d',
                        7 => 'fgd',
                        8 => 'swa',
                    ),
            'application/x-doom'               =>
                    array(
                        0 => 'wad',
                    ),
            'application/x-dtbncx+xml'         =>
                    array(
                        0 => 'ncx',
                    ),
            'application/x-dtbook+xml'         =>
                    array(
                        0 => 'dtb',
                    ),
            'application/x-dtbresource+xml'    =>
                    array(
                        0 => 'res',
                    ),
            'application/x-dvi'                =>
                    array(
                        0 => 'dvi',
                    ),
            'application/x-envoy'              =>
                    array(
                        0 => 'evy',
                    ),
            'application/x-eva'                =>
                    array(
                        0 => 'eva',
                    ),
            'application/x-font-bdf'           =>
                    array(
                        0 => 'bdf',
                    ),
            'application/x-font-ghostscript'   =>
                    array(
                        0 => 'gsf',
                    ),
            'application/x-font-linux-psf'     =>
                    array(
                        0 => 'psf',
                    ),
            'application/x-font-otf'           =>
                    array(
                        0 => 'otf',
                    ),
            'application/x-font-pcf'           =>
                    array(
                        0 => 'pcf',
                    ),
            'application/x-font-snf'           =>
                    array(
                        0 => 'snf',
                    ),
            'application/x-font-ttf'           =>
                    array(
                        0 => 'ttf',
                        1 => 'ttc',
                    ),
            'application/x-font-type1'         =>
                    array(
                        0 => 'pfa',
                        1 => 'pfb',
                        2 => 'pfm',
                        3 => 'afm',
                    ),
            'application/x-freearc'            =>
                    array(
                        0 => 'arc',
                    ),
            'application/x-futuresplash'       =>
                    array(
                        0 => 'spl',
                    ),
            'application/x-gca-compressed'     =>
                    array(
                        0 => 'gca',
                    ),
            'application/x-glulx'              =>
                    array(
                        0 => 'ulx',
                    ),
            'application/x-gnumeric'           =>
                    array(
                        0 => 'gnumeric',
                    ),
            'application/x-gramps-xml'         =>
                    array(
                        0 => 'gramps',
                    ),
            'application/x-gtar'               =>
                    array(
                        0 => 'gtar',
                    ),
            'application/x-hdf'                =>
                    array(
                        0 => 'hdf',
                    ),
            'application/x-install-instructions'   =>
                    array(
                        0 => 'install',
                    ),
            'application/x-iso9660-image'      =>
                    array(
                        0 => 'iso',
                    ),
            'application/x-java-jnlp-file'     =>
                    array(
                        0 => 'jnlp',
                    ),
            'application/x-latex'              =>
                    array(
                        0 => 'latex',
                    ),
            'application/x-lzh-compressed'     =>
                    array(
                        0 => 'lzh',
                        1 => 'lha',
                    ),
            'application/x-mie'                =>
                    array(
                        0 => 'mie',
                    ),
            'application/x-mobipocket-ebook'   =>
                    array(
                        0 => 'prc',
                        1 => 'mobi',
                    ),
            'application/x-ms-application'     =>
                    array(
                        0 => 'application',
                    ),
            'application/x-ms-shortcut'        =>
                    array(
                        0 => 'lnk',
                    ),
            'application/x-ms-wmd'             =>
                    array(
                        0 => 'wmd',
                    ),
            'application/x-ms-wmz'             =>
                    array(
                        0 => 'wmz',
                    ),
            'application/x-ms-xbap'            =>
                    array(
                        0 => 'xbap',
                    ),
            'application/x-msaccess'           =>
                    array(
                        0 => 'mdb',
                    ),
            'application/x-msbinder'           =>
                    array(
                        0 => 'obd',
                    ),
            'application/x-mscardfile'         =>
                    array(
                        0 => 'crd',
                    ),
            'application/x-msclip'             =>
                    array(
                        0 => 'clp',
                    ),
            'application/x-msdownload'         =>
                    array(
                        'default' => 'exe',
                        0 => 'exe',
                        1 => 'dll',
                        2 => 'com',
                        3 => 'bat',
                        4 => 'msi',
                    ),
            'application/x-msmediaview'        =>
                    array(
                        0 => 'mvb',
                        1 => 'm13',
                        2 => 'm14',
                    ),
            'application/x-msmetafile'         =>
                    array(
                        0 => 'wmf',
                        1 => 'wmz',
                        2 => 'emf',
                        3 => 'emz',
                    ),
            'application/x-msmoney'            =>
                    array(
                        0 => 'mny',
                    ),
            'application/x-mspublisher'        =>
                    array(
                        0 => 'pub',
                    ),
            'application/x-msschedule'         =>
                    array(
                        0 => 'scd',
                    ),
            'application/x-msterminal'         =>
                    array(
                        0 => 'trm',
                    ),
            'application/x-mswrite'            =>
                    array(
                        0 => 'wri',
                    ),
            'application/x-netcdf'             =>
                    array(
                        0 => 'nc',
                        1 => 'cdf',
                    ),
            'application/x-nzb'                =>
                    array(
                        0 => 'nzb',
                    ),
            'application/x-pkcs12'             =>
                    array(
                        0 => 'p12',
                        1 => 'pfx',
                    ),
            'application/x-pkcs7-certificates' =>
                    array(
                        0 => 'p7b',
                        1 => 'spc',
                    ),
            'application/x-pkcs7-certreqresp'  =>
                    array(
                        0 => 'p7r',
                    ),
            'application/x-rar-compressed'     =>
                    array(
                        0 => 'rar',
                    ),
            'application/x-research-info-systems'  =>
                    array(
                        0 => 'ris',
                    ),
//            'application/x-sh'                 =>
//                    array(
//                        0 => 'sh',
//                    ),
            'application/x-shar'               =>
                    array(
                        0 => 'shar',
                    ),
//            'application/x-shockwave-flash'    =>
//                    array(
//                        0 => 'swf',
//                    ),
            'application/x-silverlight-app'    =>
                    array(
                        0 => 'xap',
                    ),
//            'application/x-sql'                =>
//                    array(
//                        0 => 'sql',
//                    ),
            'application/x-stuffit'            =>
                    array(
                        0 => 'sit',
                    ),
            'application/x-stuffitx'           =>
                    array(
                        0 => 'sitx',
                    ),
            'application/x-subrip'             =>
                    array(
                        0 => 'srt',
                    ),
            'application/x-sv4cpio'            =>
                    array(
                        0 => 'sv4cpio',
                    ),
            'application/x-sv4crc'             =>
                    array(
                        0 => 'sv4crc',
                    ),
            'application/x-t3vm-image'         =>
                    array(
                        0 => 't3',
                    ),
            'application/x-tads'               =>
                    array(
                        0 => 'gam',
                    ),
            'application/x-tar'                =>
                    array(
                        0 => 'tar',
                    ),
            'application/x-tcl'                =>
                    array(
                        0 => 'tcl',
                    ),
            'application/x-tex'                =>
                    array(
                        0 => 'tex',
                    ),
            'application/x-tex-tfm'            =>
                    array(
                        0 => 'tfm',
                    ),
            'application/x-texinfo'            =>
                    array(
                        0 => 'texinfo',
                        1 => 'texi',
                    ),
            'application/x-tgif'               =>
                    array(
                        0 => 'obj',
                    ),
            'application/x-ustar'              =>
                    array(
                        0 => 'ustar',
                    ),
            'application/x-wais-source'        =>
                    array(
                        0 => 'src',
                    ),
            'application/x-x509-ca-cert'       =>
                    array(
                        0 => 'der',
                        1 => 'crt',
                    ),
            'application/x-xfig'               =>
                    array(
                        0 => 'fig',
                    ),
            'application/x-xliff+xml'          =>
                    array(
                        0 => 'xlf',
                    ),
            'application/x-xpinstall'          =>
                    array(
                        0 => 'xpi',
                    ),
            'application/x-xz'                 =>
                    array(
                        0 => 'xz',
                    ),
            'application/x-zmachine'           =>
                    array(
                        0 => 'z1',
                        1 => 'z2',
                        2 => 'z3',
                        3 => 'z4',
                        4 => 'z5',
                        5 => 'z6',
                        6 => 'z7',
                        7 => 'z8',
                    ),
            'application/xaml+xml'             =>
                    array(
                        0 => 'xaml',
                    ),
            'application/xcap-diff+xml'        =>
                    array(
                        0 => 'xdf',
                    ),
            'application/xenc+xml'             =>
                    array(
                        0 => 'xenc',
                    ),
            'application/xhtml+xml'            =>
                    array(
                        0 => 'xhtml',
                        1 => 'xht',
                    ),
            'application/xml'                  =>
                    array(
                        0 => 'xml',
                        1 => 'xsl',
                    ),
            'application/xml-dtd'              =>
                    array(
                        0 => 'dtd',
                    ),
            'application/xop+xml'              =>
                    array(
                        0 => 'xop',
                    ),
            'application/xproc+xml'            =>
                    array(
                        0 => 'xpl',
                    ),
            'application/xslt+xml'             =>
                    array(
                        0 => 'xslt',
                    ),
            'application/xspf+xml'             =>
                    array(
                        0 => 'xspf',
                    ),
            'application/xv+xml'               =>
                    array(
                        0 => 'mxml',
                        1 => 'xhvml',
                        2 => 'xvml',
                        3 => 'xvm',
                    ),
            'application/yang'                 =>
                    array(
                        0 => 'yang',
                    ),
            'application/yin+xml'              =>
                    array(
                        0 => 'yin',
                    ),
            'application/zip'                  =>
                    array(
                        0 => 'zip',
                    ),
            'audio/adpcm'                      =>
                    array(
                        0 => 'adp',
                    ),
            'audio/basic'                      =>
                    array(
                        0 => 'au',
                        1 => 'snd',
                    ),
            'audio/midi'                       =>
                    array(
                        0 => 'mid',
                        1 => 'midi',
                        2 => 'kar',
                        3 => 'rmi',
                    ),
            'audio/mp4'                        =>
                    array(
                        0 => 'mp4a',
                    ),
            'audio/mpeg'                       =>
                    array(
                        0 => 'mpga',
                        1 => 'mp2',
                        2 => 'mp2a',
                        3 => 'mp3',
                        4 => 'm2a',
                        5 => 'm3a',
                    ),
            'audio/ogg'                        =>
                    array(
                        0 => 'oga',
                        1 => 'ogg',
                        2 => 'spx',
                    ),
            'audio/s3m'                        =>
                    array(
                        0 => 's3m',
                    ),
            'audio/silk'                       =>
                    array(
                        0 => 'sil',
                    ),
            'audio/vnd.dece.audio'             =>
                    array(
                        0 => 'uva',
                        1 => 'uvva',
                    ),
            'audio/vnd.digital-winds'          =>
                    array(
                        0 => 'eol',
                    ),
            'audio/vnd.dra'                    =>
                    array(
                        0 => 'dra',
                    ),
            'audio/vnd.dts'                    =>
                    array(
                        0 => 'dts',
                    ),
            'audio/vnd.dts.hd'                 =>
                    array(
                        0 => 'dtshd',
                    ),
            'audio/vnd.lucent.voice'           =>
                    array(
                        0 => 'lvp',
                    ),
            'audio/vnd.ms-playready.media.pya' =>
                    array(
                        0 => 'pya',
                    ),
            'audio/vnd.nuera.ecelp4800'        =>
                    array(
                        0 => 'ecelp4800',
                    ),
            'audio/vnd.nuera.ecelp7470'        =>
                    array(
                        0 => 'ecelp7470',
                    ),
            'audio/vnd.nuera.ecelp9600'        =>
                    array(
                        0 => 'ecelp9600',
                    ),
            'audio/vnd.rip'                    =>
                    array(
                        0 => 'rip',
                    ),
            'audio/webm'                       =>
                    array(
                        0 => 'weba',
                    ),
            'audio/x-aac'                      =>
                    array(
                        0 => 'aac',
                    ),
            'audio/x-aiff'                     =>
                    array(
                        0 => 'aif',
                        1 => 'aiff',
                        2 => 'aifc',
                    ),
            'audio/x-caf'                      =>
                    array(
                        0 => 'caf',
                    ),
            'audio/x-flac'                     =>
                    array(
                        0 => 'flac',
                    ),
            'audio/x-matroska'                 =>
                    array(
                        0 => 'mka',
                    ),
            'audio/x-mpegurl'                  =>
                    array(
                        0 => 'm3u',
                    ),
            'audio/x-ms-wax'                   =>
                    array(
                        0 => 'wax',
                    ),
            'audio/x-ms-wma'                   =>
                    array(
                        0 => 'wma',
                    ),
            'audio/x-pn-realaudio'             =>
                    array(
                        0 => 'ram',
                        1 => 'ra',
                    ),
            'audio/x-pn-realaudio-plugin'      =>
                    array(
                        0 => 'rmp',
                    ),
            'audio/x-wav'                      =>
                    array(
                        0 => 'wav',
                    ),
            'audio/xm'                         =>
                    array(
                        0 => 'xm',
                    ),
            'chemical/x-cdx'                   =>
                    array(
                        0 => 'cdx',
                    ),
            'chemical/x-cif'                   =>
                    array(
                        0 => 'cif',
                    ),
            'chemical/x-cmdf'                  =>
                    array(
                        0 => 'cmdf',
                    ),
            'chemical/x-cml'                   =>
                    array(
                        0 => 'cml',
                    ),
            'chemical/x-csml'                  =>
                    array(
                        0 => 'csml',
                    ),
            'chemical/x-xyz'                   =>
                    array(
                        0 => 'xyz',
                    ),
            'image/bmp'                        =>
                    array(
                        0 => 'bmp',
                    ),
            'image/cgm'                        =>
                    array(
                        0 => 'cgm',
                    ),
            'image/g3fax'                      =>
                    array(
                        0 => 'g3',
                    ),
            'image/gif'                        =>
                    array(
                        0 => 'gif',
                    ),
            'image/ief'                        =>
                    array(
                        0 => 'ief',
                    ),
            'image/jpeg'                       =>
                    array(
                        'default' => 'jpeg',
                        0 => 'jpeg',
                        1 => 'jpg',
                        2 => 'jpe',
                    ),
            'image/ktx'                        =>
                    array(
                        0 => 'ktx',
                    ),
            'image/png'                        =>
                    array(
                        0 => 'png',
                    ),
            'image/prs.btif'                   =>
                    array(
                        0 => 'btif',
                    ),
            'image/sgi'                        =>
                    array(
                        0 => 'sgi',
                    ),
            'image/svg+xml'                    =>
                    array(
                        0 => 'svg',
                        1 => 'svgz',
                    ),
            'image/tiff'                       =>
                    array(
                        0 => 'tiff',
                        1 => 'tif',
                    ),
            'image/vnd.adobe.photoshop'        =>
                    array(
                        0 => 'psd',
                    ),
            'image/vnd.dece.graphic'           =>
                    array(
                        0 => 'uvi',
                        1 => 'uvvi',
                        2 => 'uvg',
                        3 => 'uvvg',
                    ),
            'image/vnd.djvu'                   =>
                    array(
                        0 => 'djvu',
                        1 => 'djv',
                    ),
            'image/vnd.dvb.subtitle'           =>
                    array(
                        0 => 'sub',
                    ),
            'image/vnd.dwg'                    =>
                    array(
                        0 => 'dwg',
                    ),
            'image/vnd.dxf'                    =>
                    array(
                        0 => 'dxf',
                    ),
            'image/vnd.fastbidsheet'           =>
                    array(
                        0 => 'fbs',
                    ),
            'image/vnd.fpx'                    =>
                    array(
                        0 => 'fpx',
                    ),
            'image/vnd.fst'                    =>
                    array(
                        0 => 'fst',
                    ),
            'image/vnd.fujixerox.edmics-mmr'   =>
                    array(
                        0 => 'mmr',
                    ),
            'image/vnd.fujixerox.edmics-rlc'   =>
                    array(
                        0 => 'rlc',
                    ),
            'image/vnd.ms-modi'                =>
                    array(
                        0 => 'mdi',
                    ),
            'image/vnd.ms-photo'               =>
                    array(
                        0 => 'wdp',
                    ),
            'image/vnd.net-fpx'                =>
                    array(
                        0 => 'npx',
                    ),
            'image/vnd.wap.wbmp'               =>
                    array(
                        0 => 'wbmp',
                    ),
            'image/vnd.xiff'                   =>
                    array(
                        0 => 'xif',
                    ),
            'image/webp'                       =>
                    array(
                        0 => 'webp',
                    ),
            'image/x-3ds'                      =>
                    array(
                        0 => '3ds',
                    ),
            'image/x-cmu-raster'               =>
                    array(
                        0 => 'ras',
                    ),
            'image/x-cmx'                      =>
                    array(
                        0 => 'cmx',
                    ),
            'image/x-freehand'                 =>
                    array(
                        0 => 'fh',
                        1 => 'fhc',
                        2 => 'fh4',
                        3 => 'fh5',
                        4 => 'fh7',
                    ),
            'image/x-icon'                     =>
                    array(
                        0 => 'ico',
                    ),
            'image/x-mrsid-image'              =>
                    array(
                        0 => 'sid',
                    ),
            'image/x-pcx'                      =>
                    array(
                        0 => 'pcx',
                    ),
            'image/x-pict'                     =>
                    array(
                        0 => 'pic',
                        1 => 'pct',
                    ),
            'image/x-portable-anymap'          =>
                    array(
                        0 => 'pnm',
                    ),
            'image/x-portable-bitmap'          =>
                    array(
                        0 => 'pbm',
                    ),
            'image/x-portable-graymap'         =>
                    array(
                        0 => 'pgm',
                    ),
            'image/x-portable-pixmap'          =>
                    array(
                        0 => 'ppm',
                    ),
            'image/x-rgb'                      =>
                    array(
                        0 => 'rgb',
                    ),
            'image/x-tga'                      =>
                    array(
                        0 => 'tga',
                    ),
            'image/x-xbitmap'                  =>
                    array(
                        0 => 'xbm',
                    ),
            'image/x-xpixmap'                  =>
                    array(
                        0 => 'xpm',
                    ),
            'image/x-xwindowdump'              =>
                    array(
                        0 => 'xwd',
                    ),
            'message/rfc822'                   =>
                    array(
                        0 => 'eml',
                        1 => 'mime',
                    ),
            'model/iges'                       =>
                    array(
                        0 => 'igs',
                        1 => 'iges',
                    ),
            'model/mesh'                       =>
                    array(
                        0 => 'msh',
                        1 => 'mesh',
                        2 => 'silo',
                    ),
            'model/vnd.collada+xml'            =>
                    array(
                        0 => 'dae',
                    ),
            'model/vnd.dwf'                    =>
                    array(
                        0 => 'dwf',
                    ),
            'model/vnd.gdl'                    =>
                    array(
                        0 => 'gdl',
                    ),
            'model/vnd.gtw'                    =>
                    array(
                        0 => 'gtw',
                    ),
            'model/vnd.mts'                    =>
                    array(
                        0 => 'mts',
                    ),
            'model/vnd.vtu'                    =>
                    array(
                        0 => 'vtu',
                    ),
            'model/vrml'                       =>
                    array(
                        0 => 'wrl',
                        1 => 'vrml',
                    ),
            'model/x3d+binary'                 =>
                    array(
                        0 => 'x3db',
                        1 => 'x3dbz',
                    ),
            'model/x3d+vrml'                   =>
                    array(
                        0 => 'x3dv',
                        1 => 'x3dvz',
                    ),
            'model/x3d+xml'                    =>
                    array(
                        0 => 'x3d',
                        1 => 'x3dz',
                    ),
            'text/cache-manifest'              =>
                    array(
                        0 => 'appcache',
                    ),
            'text/calendar'                    =>
                    array(
                        0 => 'ics',
                        1 => 'ifb',
                    ),
            'text/css'                         =>
                    array(
                        0 => 'css',
                    ),
            'text/csv'                         =>
                    array(
                        0 => 'csv',
                    ),
            'text/html'                        =>
                    array(
                        'default' => 'html',
                        0 => 'html',
                        1 => 'htm',
                    ),
            'text/n3'                          =>
                    array(
                        0 => 'n3',
                    ),
            'text/plain'                       =>
                    array(
                        'default' => 'txt',
                        0 => 'txt',
                        1 => 'text',
                        2 => 'conf',
                        3 => 'def',
                        4 => 'list',
                        5 => 'log',
                        6 => 'in',
                    ),
            'text/prs.lines.tag'               =>
                    array(
                        0 => 'dsc',
                    ),
            'text/richtext'                    =>
                    array(
                        0 => 'rtx',
                    ),
            'text/sgml'                        =>
                    array(
                        0 => 'sgml',
                        1 => 'sgm',
                    ),
            'text/tab-separated-values'        =>
                    array(
                        0 => 'tsv',
                    ),
            'text/troff'                       =>
                    array(
                        0 => 't',
                        1 => 'tr',
                        2 => 'roff',
                        3 => 'man',
                        4 => 'me',
                        5 => 'ms',
                    ),
//            'text/turtle'                      =>
//                    array(
//                        0 => 'ttl',
//                    ),
            'text/uri-list'                    =>
                    array(
                        0 => 'uri',
                        1 => 'uris',
                        2 => 'urls',
                    ),
            'text/vcard'                       =>
                    array(
                        0 => 'vcard',
                    ),
            'text/vnd.curl'                    =>
                    array(
                        0 => 'curl',
                    ),
            'text/vnd.curl.dcurl'              =>
                    array(
                        0 => 'dcurl',
                    ),
            'text/vnd.curl.mcurl'              =>
                    array(
                        0 => 'mcurl',
                    ),
            'text/vnd.curl.scurl'              =>
                    array(
                        0 => 'scurl',
                    ),
            'text/vnd.dvb.subtitle'            =>
                    array(
                        0 => 'sub',
                    ),
            'text/vnd.fly'                     =>
                    array(
                        0 => 'fly',
                    ),
            'text/vnd.fmi.flexstor'            =>
                    array(
                        0 => 'flx',
                    ),
            'text/vnd.graphviz'                =>
                    array(
                        0 => 'gv',
                    ),
            'text/vnd.in3d.3dml'               =>
                    array(
                        0 => '3dml',
                    ),
            'text/vnd.in3d.spot'               =>
                    array(
                        0 => 'spot',
                    ),
            'text/vnd.sun.j2me.app-descriptor' =>
                    array(
                        0 => 'jad',
                    ),
            'text/vnd.wap.wml'                 =>
                    array(
                        0 => 'wml',
                    ),
            'text/vnd.wap.wmlscript'           =>
                    array(
                        0 => 'wmls',
                    ),
//            'text/x-asm'                       =>
//                    array(
//                        0 => 's',
//                        1 => 'asm',
//                    ),
//            'text/x-c'                         =>
//                    array(
//                        0 => 'c',
//                        1 => 'cc',
//                        2 => 'cxx',
//                        3 => 'cpp',
//                        4 => 'h',
//                        5 => 'hh',
//                        6 => 'dic',
//                    ),
//            'text/x-fortran'                   =>
//                    array(
//                        0 => 'f',
//                        1 => 'for',
//                        2 => 'f77',
//                        3 => 'f90',
//                    ),
//            'text/x-java-source'               =>
//                    array(
//                        0 => 'java',
//                    ),
            'text/x-nfo'                       =>
                    array(
                        0 => 'nfo',
                    ),
            'text/x-opml'                      =>
                    array(
                        0 => 'opml',
                    ),
//            'text/x-pascal'                    =>
//                    array(
//                        0 => 'p',
//                        1 => 'pas',
//                    ),
            'text/x-setext'                    =>
                    array(
                        0 => 'etx',
                    ),
            'text/x-sfv'                       =>
                    array(
                        0 => 'sfv',
                    ),
            'text/x-uuencode'                  =>
                    array(
                        0 => 'uu',
                    ),
            'text/x-vcalendar'                 =>
                    array(
                        0 => 'vcs',
                    ),
            'text/x-vcard'                     =>
                    array(
                        0 => 'vcf',
                    ),
            'video/3gpp'                       =>
                    array(
                        0 => '3gp',
                    ),
            'video/3gpp2'                      =>
                    array(
                        0 => '3g2',
                    ),
            'video/h261'                       =>
                    array(
                        0 => 'h261',
                    ),
            'video/h263'                       =>
                    array(
                        0 => 'h263',
                    ),
            'video/h264'                       =>
                    array(
                        0 => 'h264',
                    ),
            'video/jpeg'                       =>
                    array(
                        0 => 'jpgv',
                    ),
            'video/jpm'                        =>
                    array(
                        0 => 'jpm',
                        1 => 'jpgm',
                    ),
            'video/mj2'                        =>
                    array(
                        0 => 'mj2',
                        1 => 'mjp2',
                    ),
            'video/mp4'                        =>
                    array(
                        0 => 'mp4',
                        1 => 'mp4v',
                        2 => 'mpg4',
                    ),
            'video/mpeg'                       =>
                    array(
                        0 => 'mpeg',
                        1 => 'mpg',
                        2 => 'mpe',
                        3 => 'm1v',
                        4 => 'm2v',
                    ),
            'video/ogg'                        =>
                    array(
                        0 => 'ogv',
                    ),
            'video/quicktime'                  =>
                    array(
                        0 => 'qt',
                        1 => 'mov',
                    ),
            'video/vnd.dece.hd'                =>
                    array(
                        0 => 'uvh',
                        1 => 'uvvh',
                    ),
            'video/vnd.dece.mobile'            =>
                    array(
                        0 => 'uvm',
                        1 => 'uvvm',
                    ),
            'video/vnd.dece.pd'                =>
                    array(
                        0 => 'uvp',
                        1 => 'uvvp',
                    ),
            'video/vnd.dece.sd'                =>
                    array(
                        0 => 'uvs',
                        1 => 'uvvs',
                    ),
            'video/vnd.dece.video'             =>
                    array(
                        0 => 'uvv',
                        1 => 'uvvv',
                    ),
            'video/vnd.dvb.file'               =>
                    array(
                        0 => 'dvb',
                    ),
            'video/vnd.fvt'                    =>
                    array(
                        0 => 'fvt',
                    ),
            'video/vnd.mpegurl'                =>
                    array(
                        0 => 'mxu',
                        1 => 'm4u',
                    ),
            'video/vnd.ms-playready.media.pyv' =>
                    array(
                        0 => 'pyv',
                    ),
            'video/vnd.uvvu.mp4'               =>
                    array(
                        0 => 'uvu',
                        1 => 'uvvu',
                    ),
            'video/vnd.vivo'                   =>
                    array(
                        0 => 'viv',
                    ),
            'video/webm'                       =>
                    array(
                        0 => 'webm',
                    ),
            'video/x-f4v'                      =>
                    array(
                        0 => 'f4v',
                    ),
            'video/x-fli'                      =>
                    array(
                        0 => 'fli',
                    ),
//            'video/x-flv'                      =>
//                    array(
//                        0 => 'flv',
//                    ),
            'video/x-m4v'                      =>
                    array(
                        0 => 'm4v',
                    ),
            'video/x-matroska'                 =>
                    array(
                        0 => 'mkv',
                        1 => 'mk3d',
                        2 => 'mks',
                    ),
            'video/x-mng'                      =>
                    array(
                        0 => 'mng',
                    ),
            'video/x-ms-asf'                   =>
                    array(
                        0 => 'asf',
                        1 => 'asx',
                    ),
            'video/x-ms-vob'                   =>
                    array(
                        0 => 'vob',
                    ),
            'video/x-ms-wm'                    =>
                    array(
                        0 => 'wm',
                    ),
            'video/x-ms-wmv'                   =>
                    array(
                        0 => 'wmv',
                    ),
            'video/x-ms-wmx'                   =>
                    array(
                        0 => 'wmx',
                    ),
            'video/x-ms-wvx'                   =>
                    array(
                        0 => 'wvx',
                    ),
            'video/x-msvideo'                  =>
                    array(
                        0 => 'avi',
                    ),
            'video/x-sgi-movie'                =>
                    array(
                        0 => 'movie',
                    ),
            'video/x-smv'                      =>
                    array(
                        0 => 'smv',
                    ),
            'x-conference/x-cooltalk'          =>
                    array(
                        0 => 'ice',
                    ),
        );

        if( array_key_exists( $mime_type, $reference ) ){
            if ( array_key_exists( 'default', $reference[$mime_type] ) ) return $reference[$mime_type]['default'];
            return $reference[$mime_type][ array_rand( $reference[$mime_type] ) ];
        }
        return null;

    }

    protected function _sanitizeName( $nameString ){

        $nameString = preg_replace( '/[^\p{L}0-9a-zA-Z_\.\-]/u', "_", $nameString );
        $nameString = preg_replace( '/[_]{2,}/', "_", $nameString );
        $nameString = str_replace( '_.', ".", $nameString );

        // project name validation
        $pattern = '/^[\p{L}\ 0-9a-zA-Z_\.\-]+$/u';

        if ( !preg_match( $pattern, $nameString, $rr ) ) {
            return false;
        }

        return $nameString;

    }

    /**
     * Extract internal reference base64 files
     * and store their index in $this->projectStructure
     *
     * @param $project_file_id
     * @param $xliff_file_array
     *
     * @return null|int $file_reference_id
     *
     * @throws Exception
     */
    protected function _extractFileReferences( $project_file_id, $xliff_file_array ){

        $fName = $this->_sanitizeName( $xliff_file_array['attr']['original'] );

        if( $fName != false ){
            $fName = mysql_real_escape_string( $fName, $this->mysql_link );
        } else {
            $fName = '';
        }

        $serialized_reference_meta     = array();
        $serialized_reference_binaries = array();
        foreach( $xliff_file_array['reference'] as $pos => $ref ){

            $found_ref = true;

            $_ext = $this->_getExtensionFromMimeType( $ref['form-type'] );
            if( $_ext !== null ){

                //insert in database if exists extension
                //and add the id_file_part to the segments insert statement

                $refName = $this->projectStructure['id_project'] . "-" . $pos . "-" . $fName . "." . $_ext;

                $serialized_reference_meta[$pos]['filename']  = $refName;
                $serialized_reference_meta[$pos]['mime_type'] = mysql_real_escape_string( $ref['form-type'], $this->mysql_link );
                $serialized_reference_binaries[$pos]['base64']    = $ref['base64'];

                $wBytes = file_put_contents( INIT::$REFERENCE_REPOSITORY . "/$refName", base64_decode( $ref['base64'] ) );

                if( !$wBytes ){
                    throw new Exception ( "Failed to import references. $wBytes Bytes written.", -11 );
                }

            }

        }

        if( isset( $found_ref ) && !empty($serialized_reference_meta) ){

            $serialized_reference_meta     = serialize( $serialized_reference_meta );
            $serialized_reference_binaries = serialize( $serialized_reference_binaries );
            $queries = "INSERT INTO file_references ( id_project, id_file, part_filename, serialized_reference_meta, serialized_reference_binaries ) VALUES ( " . $this->projectStructure['id_project'] . ", $project_file_id, '$fName', '$serialized_reference_meta', '$serialized_reference_binaries' )";
            mysql_query( $queries, $this->mysql_link );

            $affected          = mysql_affected_rows( $this->mysql_link );
            $last_id           = "SELECT LAST_INSERT_ID() as fpID";
            $link_identifier   = mysql_query( $last_id, $this->mysql_link );
            $result            = mysql_fetch_assoc( $link_identifier );

            //last Insert id
            $file_reference_id = $result[ 'fpID' ];
            $this->projectStructure[ 'file_references' ]->offsetSet( $project_file_id, $file_reference_id );

            if( !$affected || !$file_reference_id ){
                throw new Exception ( "Failed to import references.", -12 );
            }

            return $file_reference_id;

        }


    }

}
