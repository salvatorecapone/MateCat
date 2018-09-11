<?php
/**
 * Created by PhpStorm.
 * User: vincenzoruffa
 * Date: 07/09/2018
 * Time: 11:21
 */

class QualityReport_QualityReportSegmentModel {

    protected $qa_categories;

    public function getSegmentsIdForQR( Chunks_ChunkStruct $chunk, $step = 10, $ref_segment, $where = "after", $options = [] ) {

        $segmentsDao = new \Segments_SegmentDao;
        $segments_id = $segmentsDao->getSegmentsIdForQR( $chunk->id, $chunk->password, $step, $ref_segment, $where );

        return $segments_id;
    }

    public function getSegmentsForQR( $segments_id, $features ) {
        $segmentsDao = new \Segments_SegmentDao;
        $data        = $segmentsDao->getSegmentsForQr( $segments_id );

        $codes = $features->getCodes();
        if ( in_array( Features\ReviewExtended::FEATURE_CODE, $codes ) OR in_array( Features\ReviewImproved::FEATURE_CODE, $codes ) ) {
            $issues = \Features\ReviewImproved\Model\QualityReportDao::getIssuesBySegments( $segments_id );
        } else {
            $reviseDao          = new \Revise_ReviseDAO();
            $segments_revisions = $reviseDao->readBySegments( $segments_id );
            $issues             = $this->makeIssuesDataUniform( $segments_revisions );
        }

        $commentsDao = new \Comments_CommentDao;
        $comments    = $commentsDao->getThreadsBySegments( $segments_id );


        if ( in_array( Features\TranslationVersions::FEATURE_CODE, $codes ) ) {
            $translationVersionDao = new Translations_TranslationVersionDao;
            $last_translations     = $translationVersionDao->getLastTranslationsBySegments( $segments_id );
            $last_revisions        = $translationVersionDao->getLastRevisionsBySegments( $segments_id );
        } else {
            if ( !isset( $segments_revisions ) ) {
                $reviseDao          = new \Revise_ReviseDAO();
                $segments_revisions = $reviseDao->readBySegments( $segments_id );
            }

            $last_translations = $this->makeSegmentsVersionsUniform( $segments_revisions );
        }


        $segments = [];
        foreach ( $data as $i => $seg ) {

            $seg->warnings      = $seg->getLocalWarning();
            $seg->pee           = $seg->getPEE();
            $seg->ice_modified  = $seg->isICEModified();
            $seg->secs_per_word = $seg->getSecsPerWord();

            $seg->parsed_time_to_edit = CatUtils::parse_time_to_edit( $seg->time_to_edit );

            $seg->segment = CatUtils::rawxliff2view( $seg->segment );

            $seg->translation = CatUtils::rawxliff2view( $seg->translation );

            foreach ( $issues as $issue ) {
                if ( $issue->segment_id == $seg->sid ) {
                    $seg->issues[] = $issue;
                }
            }

            foreach ( $comments as $comment ) {
                $comment->templateMessage();
                if ( $comment->id_segment == $seg->sid ) {
                    $seg->comments[] = $comment;
                }
            }

            if ( $seg->status == Constants_TranslationStatus::STATUS_TRANSLATED ) {
                $seg->last_translation = $seg->translation;
            } else {
                foreach ( $last_translations as $last_translation ) {
                    if ( $last_translation->id_segment == $seg->sid ) {
                        $seg->last_translation = $last_translation->translation;
                    }
                }
            }

            if ( $seg->status == Constants_TranslationStatus::STATUS_APPROVED ) {
                $seg->last_revision = $seg->translation;
            } else {
                if ( isset( $last_revisions ) ) {
                    foreach ( $last_revisions as $last_revision ) {
                        if ( $last_revision->id_segment == $seg->sid ) {
                            $seg->last_revision = $last_revision->translation;
                        }
                    }
                }
            }

            $seg->pee_translation_revise     = $seg->getPEEBwtTranslationRevise();
            $seg->pee_translation_suggestion = $seg->getPEEBwtTranslationSuggestion();

            $segments[] = $seg;
        }

        return $segments;

    }

    public function makeSegmentsVersionsUniform( $segments_versions ) {
        $array = [];
        foreach ( $segments_versions as $segment_version ) {
            $array[] = new \DataAccess\ShapelessConcreteStruct([
                    'id_segment' => $segment_version->id_segment,
                    'translation' => $segment_version->original_translation,
                    'version_number' => 0,
                    'creation_date' => null,
                    'is_review' => 0
            ]);
        }

        return $array;

    }

    public function makeIssuesDataUniform( $issues ) {
        $issues_categories = [];
        foreach ( $issues as $issue ) {
            $issues_categories = array_merge( $issues_categories, $this->makeIssueDataUniform( $issue ) );
        }

        return $issues_categories;

    }

    /**
     * This is a temporary method. It helps to uniformize issues data coming from different features.
     * From the oldest one version to the new one.
     *
     * @param $issue
     *
     * @return \DataAccess\ShapelessConcreteStruct[]
     */

    public function makeIssueDataUniform( $issue ) {

        $categories_values = [ 'err_typing', 'err_translation', 'err_terminology', 'err_language', 'err_style' ];

        $categories = [];
        foreach ( $categories_values as $category_value ) {

            $issue_old_severity = $issue->{$category_value};
            if ( $issue_old_severity == "" ) {
                continue;
            }

            $categories[] = new \DataAccess\ShapelessConcreteStruct( [
                    "segment_id"          => $issue->id_segment,
                    "issue_id"            => null,
                    "issue_create_date"   => null,
                    "issue_replies_count" => "0",
                    "issue_start_offset"  => "0",
                    "issue_end_offset"    => "0",
                    "issue_category"      => constant( "Constants_Revise::" . strtoupper( $category_value ) ),
                    "category_options"    => null,
                    "issue_severity"      => $issue->{$category_value},
                    "issue_comment"       => null,
                    "target_text"         => null,
                    "issue_uid"           => null,
                    "warning_scope"       => null,
                    "warning_data"        => null,
                    "warning_severity"    => null
            ] );

        }

        return $categories;

    }


}
