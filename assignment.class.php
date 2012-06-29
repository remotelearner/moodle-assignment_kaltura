<?php // $Id: assignment.class.php,v 1.33.2.5 2008/04/08 03:02:49 scyrma Exp $
defined('MOODLE_INTERNAL') || die();

/**
 * Extend the base assignment class for assignments where you upload a single file
 *
 */
class assignment_kaltura extends assignment_base {

    function assignment_kaltura($cmid = 'staticonly', $assignment = NULL, $cm = NULL, $course = NULL, $groupid = NULL) {
        global $CFG;

        require_once($CFG->dirroot.'/blocks/kaltura/lib.php');
        require_once($CFG->dirroot.'/blocks/kaltura/locallib.php');
        require_js($CFG->wwwroot.'/blocks/kaltura/js/jquery.js');
        require_js($CFG->wwwroot.'/blocks/kaltura/js/kvideo.js');
        require_js($CFG->wwwroot.'/blocks/kaltura/js/swfobject.js');

        parent::assignment_base($cmid, $assignment, $cm, $course, $groupid);
        $this->release  = '1.2';

        // Add Kaltura block instance (needed for backup and restor purposes)
        $blockid = get_field('block', 'id', 'name', 'kaltura');

        if ($blockid and !empty($course)) {

            if (!record_exists('block_instance', 'pageid', $course->id, 'blockid', $blockid)) {

                $block              = new stdClass();
                $block->blockid     = $blockid;
                $block->pageid      = $course->id;
                $block->pagetype    = 'course-view';
                $block->position    = 'r';
                $block->weight      = '3';
                $block->visible     = 0;
                $block->configdata  = 'Tjs=';

                insert_record('block_instance', $block);
            }
        }
    }

    function print_student_answer($userid, $return=false) {
        global $CFG, $USER;

        $width_height = '';

        if ($submission = $this->get_submission($userid)) {

            $entry = get_record('block_kaltura_entries', 'context', "S_" . $submission->id);

             $videodata = kaltura_get_video_type_entry($submission->data1);

            if (false !== $videodata->entryid) {

                $player_url = $CFG->wwwroot.'/blocks/kaltura/kpreview.php?entry_id='. $videodata->entryid .
                                            '&design=' . $entry->design . '&width=' . get_width($entry) .
                                            '&dimensions=' . $entry->dimensions . '&type=' . $videodata->type;

                if (empty($entry)) {

                    $width_height = '{width:400, height:382}';
                } else {

                    $width = get_width($entry) + 5;
                    $height = get_height($entry);// + 70;
                    $width_height = '{width:' . $width .  ', height:' . $height . '}';
                }

                $thumbnailurl = KalturaHelpers::getThumbnailUrl(null, $videodata->entryid, 100, 100);

                $thumbnail = '<div class="video_assignment"><a href="#" '.
                             'onclick="kalturaInitModalBox(\''. $player_url .'\',  ' . $width_height .
                             ');"><img src="'. $thumbnailurl .
                             '" /></a></div>'; //+10
                $output = $thumbnail;
            } else {
                $output = get_string('videonotready', 'assignment_kaltura');
            }
        }

        $output = '<div class="files">'.$output.'</div>';
        return $output;
    }

    function view_header_tmp($subpage = '') {

        global $CFG;


        if ($subpage) {
            $navigation = build_navigation($subpage, $this->cm);
        } else {
            $navigation = build_navigation('', $this->cm);
        }

        $meta = '<link rel="stylesheet" type="text/css" href="'.$CFG->wwwroot.'/blocks/kaltura/styles.css" />'."\n";

        groups_print_activity_menu($this->cm, 'view.php?id='.$this->cm->course.'&modtype='.$this->cm->modname.'&modid=' . $this->cm->id);


        echo '<div class="reportlink">'.$this->submittedlink().'</div>';
        echo '<div class="clearer"></div>';
    }

    function view() {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/blocks/kaltura/jsportal.php');
        require_js($CFG->wwwroot . '/blocks/kaltura/js/kaltura.main.js');

        require_capability('mod/assignment:view', $this->context);

        add_to_log($this->course->id, 'assignment', 'view', "view.php?id={$this->cm->id}", $this->assignment->id, $this->cm->id);

        $this->view_header();

        if ($this->assignment->timeavailable > time()
            and !has_capability('mod/assignment:grade', $this->context)      // grading user can see it anytime
            and $this->assignment->var3) {                                    // force hiding before available date

            print_simple_box_start('center', '', '', 0, 'generalbox', 'intro');
            print_string('notavailableyet', 'assignment');
            print_simple_box_end();
        } else {
            $this->view_intro();
        }

        $this->view_dates();

        if (has_capability('mod/assignment:submit', $this->context)) {
            $submission = $this->get_submission($USER->id);

            $this->view_feedback();

            if (!$this->drafts_tracked() or !$this->isopen() or $this->is_finalized($submission)) {
                print_heading(get_string('submission', 'assignment'), '', 3);
            } else {
                print_heading(get_string('submissiondraft', 'assignment'), '', 3);
            }

            if ($submission) {

                // Print caching notification
                list($notify, $minutes) = is_video_cached($submission->timemodified);

                if ($notify and ($minutes > 0) ) {
                    echo notify(get_string('videocache', 'block_kaltura', $minutes), 'notifyproblem', 'center', true);
                }

                print_simple_box($this->print_user_files($USER->id, true), 'center');

            } else {
                if (!$this->isopen() or $this->is_finalized($submission)) {
                    //print_simple_box(get_string('nofiles', 'assignment'), 'center');
                } else {
                    // Do nothing for now.
                }
            }

        $marked = (isset($submission->timemarked) and !empty($submission->timemarked)) ? true : false;

        if (has_capability('mod/assignment:submit', $this->context)  &&
            $this->isopen() &&
            ($this->assignment->resubmit || !$marked)) {

            $this->view_upload_form();
        }

            $this->view_final_submission();
        }
    }


    function view_upload_form() {

        global $CFG, $USER;

        $submission = $this->get_submission($USER->id);

        if ($this->is_finalized($submission)) {
            // no uploading
            return;
        }

        $kalturaprotal = new kaltura_jsportal();
        $kalturaprotal->print_javascript(
                            array('wwwroot' => $CFG->wwwroot,
                                  'ssskey' => $USER->sesskey,
                                  'userid' => $USER->id,
                                  'replacesubmission' => get_string('replacesubmission', 'assignment_kaltura')
                                  ));

        if ($this->can_upload_file($submission)) {

            // Add javascript exclusively used by this activity type
            require_js($CFG->wwwroot . '/blocks/kaltura/js/video.assignment.js');

            echo '<div style="text-align:center">'."\n";
            echo '<form id="kaltura_assignment_form" enctype="multipart/form-data" method="post" '.
                "action=\"upload.php\">"."\n";

            echo '<fieldset class="invisiblefieldset">'."\n";
            echo '<input type="hidden" name="id" value="'.$this->cm->id.'" />'."\n";
            echo '<input type="hidden" name="wwwroot" value="'.$CFG->wwwroot.'" />'."\n";
            echo '<input type="hidden" name="widget" id="id_widget" value="'. ((isset($submission->data1))? $submission->data1: '') .'" />'."\n";
            echo '</fieldset>'."\n";

            echo '<div style="border:1px solid #bcbab4;background-color:#f5f1e9;margin: 0 auto;width:140px;height:105px;text-align:center;font-size:85%;display:none" id="divWait">'."\n";
            echo '</div><br />'."\n";

            echo get_wait_image('divWait', 'id_widget', false, false);

            $cw_url = $CFG->wwwroot.'/blocks/kaltura/kcw.php?mod=assignment&upload_type=mix';
//            $cw_url = $CFG->wwwroot.'/blocks/kaltura/kcw.php?mod=assignment&upload_type=video';

            if(isset($submission->data1) && $submission->data1 != '') {

                if ($this->assignment->resubmit) { // check if re-submit allowed

                    echo '<div><input type="button"  id="btn_remix" name="Remix Video Submission" value="' .
                            get_string('remixsubmission', 'assignment_kaltura') . '" onclick="kalturaInitModalBox(\'' .
                            $CFG->wwwroot . '/blocks/kaltura/keditor.php?entry_id=' . $submission->data1 .
                            '\' , {width:890, height:546});" />'."\n";

                    echo '<input type="button"  id="btn_addvid" name="Add Video Submission" value="' .
                            get_string('replacesubmission', 'assignment_kaltura') . '" onclick="kalturaInitModalBox(\'' .
                            $cw_url .'\', {width: 760, height: 422});" />'."\n";

                    echo '<input type="submit" name="save" value="'.get_string('submit').'" /></div>'."\n";
                }
            } else {
                echo '<div>'."\n";


                echo '<input type="button"  style="display:none" id="btn_remix" name="Remix Video Submission" value="' .
                        get_string('remixsubmission', 'assignment_kaltura') . '" />'."\n";

                echo '<input type="button" id="btn_addvid" name="Add Video Submission" value="' .
                        get_string('addvideosubmission', 'assignment_kaltura') . '" onclick="kalturaInitModalBox(\'' .
                        $cw_url .'\', {width: 760, height: 422});" />'."\n";

                echo '<input type="submit" id="newsave" name="save" value="'.get_string('submit').'" /></div>'."\n";
            }

            echo '</form>';
            echo '</div>';
        }
    }

    /**
     * Produces a list of links to the files uploaded by a user
     *
     * @param $userid int optional id of the user. If 0 then $USER->id is used.
     * @param $return boolean optional defaults to false. If true the list is returned rather than printed
     * @return string optional
     */
    function print_user_files($userid = 0, $return = false) {
        global $CFG;

        require_js($CFG->wwwroot . '/blocks/kaltura/js/flashversion.js');
        require_js($CFG->wwwroot . '/blocks/kaltura/js/kdp_flash_ver_tester.js');

        $submission = $this->get_submission($userid);

        $video_entry = kaltura_get_video_type_entry($submission->data1);

        if (false !== $video_entry->entryid) {

            $entry = get_record('block_kaltura_entries', 'context', "S_" . $submission->id);
            $video_entry->type = 1; //DEBUGGING
            $display_submission = embed_kaltura($video_entry->entryid, 400, 332, $video_entry->type, $entry->design);

        } else {
            $display_submission = get_string('videonotready', 'assignment_kaltura');
        }

        if ($return) {
            return $display_submission;
        }

        echo $display_submission;

    }

    function view_final_submission() {
        global $CFG, $USER;

        $submission = $this->get_submission($USER->id);

        if ($this->isopen() and $this->can_finalize($submission)) {

            //print final submit button
            print_heading(get_string('submitformarking','assignment'), '', 3);
            echo '<div style="text-align:center">';
            echo '<form method="post" action="upload.php">';
            echo '<fieldset class="invisiblefieldset">';
            echo '<input type="hidden" name="id" value="'.$this->cm->id.'" />';
            echo '<input type="hidden" name="action" value="finalize" />';
            echo '<input type="submit" name="formarking" value="'.get_string('sendformarking', 'assignment').'" />';
            echo '</fieldset>';
            echo '</form>';
            echo '</div>';

        } else if (!$this->isopen()) {

            print_heading(get_string('nomoresubmissions','assignment'), '', 3);

        } else if ($this->drafts_tracked() and $state = $this->is_finalized($submission)) {

            if ($state == ASSIGNMENT_STATUS_SUBMITTED) {
                print_heading(get_string('submitedformarking', 'assignment'), '', 3);
            } else {
                print_heading(get_string('nomoresubmissions', 'assignment'), '', 3);
            }
        } else {
            //no submission yet
        }
    }

  function drafts_tracked() {
        return !empty($this->assignment->var4);
    }

    function is_finalized($submission) {
        if (!$this->drafts_tracked()) {
            return '';

        } else if (empty($submission)) {
            return '';

        } else if ($submission->data2 == ASSIGNMENT_STATUS_SUBMITTED or $submission->data2 == ASSIGNMENT_STATUS_CLOSED) {
            return $submission->data2;

        } else {
            return '';
        }
    }

    function can_upload_file($submission) {
        global $USER;

        if (has_capability('mod/assignment:submit', $this->context)           // can submit
          and $this->isopen()                                                 // assignment not closed yet
          and (empty($submission) or $submission->userid == $USER->id)        // his/her own submission
          and !$this->is_finalized($submission)) { // no uploading after final submission

            return true;
        } else {
            return false;
        }
    }

    function can_finalize($submission) {

        global $USER;

        if (!$this->drafts_tracked()) {
            return false;
        }

        if ($this->is_finalized($submission)) {
            return false;
        }

        if (has_capability('mod/assignment:grade', $this->context)) {
            return true;
        } elseif (has_capability('mod/assignment:submit', $this->context)    // can submit
          and $this->isopen()                                                 // assignment not closed yet
          and !empty($submission)                                             // submission must exist
          and $submission->userid == $USER->id                                // his/her own submission
          and ($this->count_user_files($USER->id))) {    // something must be submitted
            return true;
        } else {
            return false;
        }
    }

    function upload() {

        global $CFG, $USER;

        $mode   = optional_param('mode', '', PARAM_ALPHA);
        $offset = optional_param('offset', 0, PARAM_INT);
        $widget = required_param('widget', PARAM_TEXT);

        $returnurl = 'view.php?id='.$this->cm->id;

        $submission = $this->get_submission($USER->id);

        $this->view_header(get_string('upload'));

        if (!$this->can_upload_file($submission)) {

            notify(get_string('uploaderror', 'assignment_kaltura'));
            print_continue($returnurl);
            $this->view_footer();
            die;
        }

        $submission             = $this->get_submission($USER->id, true); //create new submission if needed
        $updated                = new object();
        $updated->id            = $submission->id;
        $updated->timemodified  = time();
        $updated->data1         = $widget;

        if (update_record('assignment_submissions', $updated)) {

            add_to_log($this->course->id, 'assignment', 'upload',
                    'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);

            $submission = $this->get_submission($USER->id);

            if (!$this->add_kaltura_entry($widget, $submission)) {

                notify(get_string('kentrieserror', 'assignment_kaltura'));
                print_continue($returnurl);
                $this->view_footer();
                die();
            }

            $this->update_grade($submission);

            if (!$this->drafts_tracked()) {
                $this->email_teachers($submission);
            }

            print_heading(get_string('uploadedfile'));
            print_continue($returnurl);
            $this->view_footer();
        } else {

            $this->view_header(get_string('upload'));
            notify(get_string('uploaderror', 'assignment_kaltura'));
            print_continue($returnurl);
            $this->view_footer();
            die;
        }
    }

    function add_kaltura_entry ($widget, $submission) {

        if (empty($submission->data1)) {

            delete_records('assignment_submissions', 'id', $submission->id);
            notify(get_string('uploaderror', 'assignment_kaltura'));

            $returnurl = 'view.php?id='.$this->cm->id;
            print_continue($returnurl);

            $this->view_footer();

            die();
        }

        $entry = get_record('block_kaltura_entries', 'context', 'S_' . $submission->id);

        $user = get_record('user', 'id', $submission->userid, '', '', '', '', 'username,firstname,lastname');

        if (!empty($user)) {
            $user = $user->firstname . ' ' . $user->lastname . '('. $user->username . ')';
        } else {
            $user = '';
        }

        if (!empty($entry)) {

            $entry->entry_id        = $widget;

            update_record('block_kaltura_entries', $entry);
            return true;
        }

        $courseid = get_field('assignment', 'course', 'id', $submission->assignment);

        if ($courseid) {

            $entry                  = new kaltura_entry;
            $entry->title           = $user . ' Video Submission';
            $entry->courseid        = $courseid;
            $entry->context         = 'S_' . $submission->id;
            $entry->entry_type      = KalturaEntryType::MEDIA_CLIP;
            $entry->media_type      = KalturaMediaType::VIDEO;
            $entry->entry_id        = $widget;

            $entry->id = insert_record('block_kaltura_entries', $entry);

            return true;
        } else {
            return false;
        }

    }

    function delete_instance($assignment) {

        $submissions = get_records('assignment_submissions', 'assignment', $assignment->id);

        if (empty($submissions)) {
            $submissions = array();
        }

        foreach ($submissions as $submission) {
            delete_records('block_kaltura_entries', 'context', 'S_' . $submission->id);
        }

        return parent::delete_instance($assignment);
    }

    function setup_elements(&$mform) {
        global $CFG, $COURSE;

        if (KalturaHelpers::getPlatformKey('kaltura_partner_id', 'none') == 'none') {
            print_error('blocknotinitialized', 'block_kaltura');
            die();
        }

        $ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));

        $mform->addElement('select', 'resubmit', get_string('allowresubmit', 'assignment'), $ynoptions);
        $mform->setHelpButton('resubmit', array('resubmit', get_string('allowresubmit', 'assignment'), 'assignment'));
        $mform->setDefault('resubmit', 0);

        $mform->addElement('select', 'emailteachers', get_string('emailteachers', 'assignment'), $ynoptions);
        $mform->setHelpButton('emailteachers', array('emailteachers', get_string('emailteachers', 'assignment'), 'assignment'));
        $mform->setDefault('emailteachers', 0);

    }

}

?>