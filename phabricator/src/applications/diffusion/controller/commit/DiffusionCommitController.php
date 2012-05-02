<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class DiffusionCommitController extends DiffusionController {

  const CHANGES_LIMIT = 100;

  private $auditAuthorityPHIDs;

  public function willProcessRequest(array $data) {
    // This controller doesn't use blob/path stuff, just pass the dictionary
    // in directly instead of using the AphrontRequest parsing mechanism.
    $drequest = DiffusionRequest::newFromDictionary($data);
    $this->diffusionRequest = $drequest;
  }

  public function processRequest() {
    $drequest = $this->getDiffusionRequest();
    $request = $this->getRequest();
    $user = $request->getUser();

    if ($request->getStr('diff')) {
      return $this->buildRawDiffResponse($drequest);
    }

    $callsign = $drequest->getRepository()->getCallsign();

    $content = array();
    $content[] = $this->buildCrumbs(array(
      'commit' => true,
    ));

    $repository = $drequest->getRepository();
    $commit = $drequest->loadCommit();

    if (!$commit) {
      // TODO: Make more user-friendly.
      throw new Exception('This commit has not parsed yet.');
    }

    $commit_data = $drequest->loadCommitData();
    $commit->attachCommitData($commit_data);

    $is_foreign = $commit_data->getCommitDetail('foreign-svn-stub');
    if ($is_foreign) {
      $subpath = $commit_data->getCommitDetail('svn-subpath');

      $error_panel = new AphrontErrorView();
      $error_panel->setWidth(AphrontErrorView::WIDTH_WIDE);
      $error_panel->setTitle('Commit Not Tracked');
      $error_panel->setSeverity(AphrontErrorView::SEVERITY_WARNING);
      $error_panel->appendChild(
        "This Diffusion repository is configured to track only one ".
        "subdirectory of the entire Subversion repository, and this commit ".
        "didn't affect the tracked subdirectory ('".
        phutil_escape_html($subpath)."'), so no information is available.");
      $content[] = $error_panel;
    } else {
      $engine = PhabricatorMarkupEngine::newDifferentialMarkupEngine();

      require_celerity_resource('diffusion-commit-view-css');
      require_celerity_resource('phabricator-remarkup-css');

      $parent_query = DiffusionCommitParentsQuery::newFromDiffusionRequest(
        $drequest);

      $headsup_panel = new AphrontHeadsupView();
      $headsup_panel->setHeader('Commit Detail');
      $headsup_panel->setActionList(
        $this->renderHeadsupActionList($commit));
      $headsup_panel->setProperties(
        $this->getCommitProperties(
          $commit,
          $commit_data,
          $parent_query->loadParents()));

      $headsup_panel->appendChild(
        '<div class="diffusion-commit-message phabricator-remarkup">'.
          $engine->markupText($commit_data->getCommitMessage()).
        '</div>');

      $content[] = $headsup_panel;
    }

    $query = new PhabricatorAuditQuery();
    $query->withCommitPHIDs(array($commit->getPHID()));
    $audit_requests = $query->execute();

    $this->auditAuthorityPHIDs =
      PhabricatorAuditCommentEditor::loadAuditPHIDsForUser($user);

    $content[] = $this->buildAuditTable($commit, $audit_requests);
    $content[] = $this->buildComments($commit);

    $change_query = DiffusionPathChangeQuery::newFromDiffusionRequest(
      $drequest);
    $changes = $change_query->loadChanges();

    $content[] = $this->buildMergesTable($commit);

    $original_changes_count = count($changes);
    if ($request->getStr('show_all') !== 'true' &&
        $original_changes_count > self::CHANGES_LIMIT) {
      $changes = array_slice($changes, 0, self::CHANGES_LIMIT);
    }

    $change_table = new DiffusionCommitChangeTableView();
    $change_table->setDiffusionRequest($drequest);
    $change_table->setPathChanges($changes);

    $count = count($changes);

    $bad_commit = null;
    if ($count == 0) {
      $bad_commit = queryfx_one(
        id(new PhabricatorRepository())->establishConnection('r'),
        'SELECT * FROM %T WHERE fullCommitName = %s',
        PhabricatorRepository::TABLE_BADCOMMIT,
        'r'.$callsign.$commit->getCommitIdentifier());
    }

    if ($bad_commit) {
      $error_panel = new AphrontErrorView();
      $error_panel->setWidth(AphrontErrorView::WIDTH_WIDE);
      $error_panel->setTitle('Bad Commit');
      $error_panel->appendChild(
        phutil_escape_html($bad_commit['description']));

      $content[] = $error_panel;
    } else if ($is_foreign) {
      // Don't render anything else.
    } else if (!count($changes)) {
      $no_changes = new AphrontErrorView();
      $no_changes->setWidth(AphrontErrorView::WIDTH_WIDE);
      $no_changes->setSeverity(AphrontErrorView::SEVERITY_WARNING);
      $no_changes->setTitle('Not Yet Parsed');
      // TODO: This can also happen with weird SVN changes that don't do
      // anything (or only alter properties?), although the real no-changes case
      // is extremely rare and might be impossible to produce organically. We
      // should probably write some kind of "Nothing Happened!" change into the
      // DB once we parse these changes so we can distinguish between
      // "not parsed yet" and "no changes".
      $no_changes->appendChild(
        "This commit hasn't been fully parsed yet (or doesn't affect any ".
        "paths).");
      $content[] = $no_changes;
    } else {
      $change_panel = new AphrontPanelView();
      $change_panel->setHeader("Changes (".number_format($count).")");

      if ($count !== $original_changes_count) {
        $show_all_button = phutil_render_tag(
          'a',
          array(
            'class'   => 'button green',
            'href'    => '?show_all=true',
          ),
          phutil_escape_html('Show All Changes'));
        $warning_view = id(new AphrontErrorView())
          ->setSeverity(AphrontErrorView::SEVERITY_WARNING)
          ->setTitle(sprintf(
                       "Showing only the first %d changes out of %s!",
                       self::CHANGES_LIMIT,
                       number_format($original_changes_count)));

        $change_panel->appendChild($warning_view);
        $change_panel->addButton($show_all_button);
      }

      $change_panel->appendChild($change_table);

      $content[] = $change_panel;

      $changesets = DiffusionPathChange::convertToDifferentialChangesets(
        $changes);

      $vcs = $repository->getVersionControlSystem();
      switch ($vcs) {
        case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
          $vcs_supports_directory_changes = true;
          break;
        case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
          $vcs_supports_directory_changes = false;
          break;
        default:
          throw new Exception("Unknown VCS.");
      }

      $references = array();
      foreach ($changesets as $key => $changeset) {
        $file_type = $changeset->getFileType();
        if ($file_type == DifferentialChangeType::FILE_DIRECTORY) {
          if (!$vcs_supports_directory_changes) {
            unset($changesets[$key]);
            continue;
          }
        }

        $references[$key] = $drequest->generateURI(
          array(
            'action' => 'rendering-ref',
            'path'   => $changeset->getFilename(),
          ));
      }

      // TODO: Some parts of the views still rely on properties of the
      // DifferentialChangeset. Make the objects ephemeral to make sure we don't
      // accidentally save them, and then set their ID to the appropriate ID for
      // this application (the path IDs).
      $pquery = new DiffusionPathIDQuery(mpull($changesets, 'getFilename'));
      $path_ids = $pquery->loadPathIDs();
      foreach ($changesets as $changeset) {
        $changeset->makeEphemeral();
        $changeset->setID($path_ids[$changeset->getFilename()]);
      }

      $change_list = new DifferentialChangesetListView();
      $change_list->setChangesets($changesets);
      $change_list->setRenderingReferences($references);
      $change_list->setRenderURI('/diffusion/'.$callsign.'/diff/');
      $change_list->setRepository($repository);
      $change_list->setUser($user);

      $change_list->setStandaloneURI(
        '/diffusion/'.$callsign.'/diff/');
      $change_list->setRawFileURIs(
        // TODO: Implement this, somewhat tricky if there's an octopus merge
        // or whatever?
        null,
        '/diffusion/'.$callsign.'/diff/?view=r');

      $change_list->setInlineCommentControllerURI(
        '/diffusion/inline/'.phutil_escape_uri($commit->getPHID()).'/');

      // TODO: This is pretty awkward, unify the CSS between Diffusion and
      // Differential better.
      require_celerity_resource('differential-core-view-css');
      $change_list =
        '<div class="differential-primary-pane">'.
          $change_list->render().
        '</div>';

      $content[] = $change_list;
    }

    $content[] = $this->buildAddCommentView($commit, $audit_requests);

    return $this->buildStandardPageResponse(
      $content,
      array(
        'title' => 'r'.$callsign.$commit->getCommitIdentifier(),
      ));
  }

  private function getCommitProperties(
    PhabricatorRepositoryCommit $commit,
    PhabricatorRepositoryCommitData $data,
    array $parents) {
    assert_instances_of($parents, 'PhabricatorRepositoryCommit');
    $user = $this->getRequest()->getUser();

    $task_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $commit->getPHID(),
      PhabricatorEdgeConfig::TYPE_COMMIT_HAS_TASK);

    $phids = $task_phids;
    if ($data->getCommitDetail('authorPHID')) {
      $phids[] = $data->getCommitDetail('authorPHID');
    }
    if ($data->getCommitDetail('reviewerPHID')) {
      $phids[] = $data->getCommitDetail('reviewerPHID');
    }
    if ($data->getCommitDetail('differential.revisionPHID')) {
      $phids[] = $data->getCommitDetail('differential.revisionPHID');
    }
    if ($parents) {
      foreach ($parents as $parent) {
        $phids[] = $parent->getPHID();
      }
    }

    $handles = array();
    if ($phids) {
      $handles = id(new PhabricatorObjectHandleData($phids))
        ->loadHandles();
    }

    $props = array();

    if ($commit->getAuditStatus()) {
      $status = PhabricatorAuditCommitStatusConstants::getStatusName(
        $commit->getAuditStatus());
      $props['Status'] = phutil_render_tag(
        'strong',
        array(),
        phutil_escape_html($status));
    }

    $props['Committed'] = phabricator_datetime($commit->getEpoch(), $user);

    $author_phid = $data->getCommitDetail('authorPHID');
    if ($data->getCommitDetail('authorPHID')) {
      $props['Author'] = $handles[$author_phid]->renderLink();
    } else {
      $props['Author'] = phutil_escape_html($data->getAuthorName());
    }

    $reviewer_phid = $data->getCommitDetail('reviewerPHID');
    $reviewer_name = $data->getCommitDetail('reviewerName');
    if ($reviewer_phid) {
      $props['Reviewer'] = $handles[$reviewer_phid]->renderLink();
    } else if ($reviewer_name) {
      $props['Reviewer'] = phutil_escape_html($reviewer_name);
    }

    $revision_phid = $data->getCommitDetail('differential.revisionPHID');
    if ($revision_phid) {
      $props['Differential Revision'] = $handles[$revision_phid]->renderLink();
    }

    if ($parents) {
      $parent_links = array();
      foreach ($parents as $parent) {
        $parent_links[] = $handles[$parent->getPHID()]->renderLink();
      }
      $props['Parents'] = implode(' &middot; ', $parent_links);
    }


    $request = $this->getDiffusionRequest();

    $contains = DiffusionContainsQuery::newFromDiffusionRequest($request);
    $branches = $contains->loadContainingBranches();

    if ($branches) {
      // TODO: Separate these into 'tracked' and other; link tracked branches.
      $branches = implode(', ', array_keys($branches));
      $branches = phutil_escape_html($branches);
      $props['Branches'] = $branches;
    }


    $tags = $this->buildTags($request);
    if ($tags) {
      $props['Tags'] = $tags;
    }

    $refs = $this->buildRefs($request);
    if ($refs) {
      $props['Refs'] = $refs;
    }

    if ($task_phids) {
      $task_list = array();
      foreach ($task_phids as $phid) {
        $task_list[] = $handles[$phid]->renderLink();
      }
      $task_list = implode('<br />', $task_list);
      $props['Tasks'] = $task_list;
    }

    return $props;
  }

  private function buildAuditTable(
    PhabricatorRepositoryCommit $commit,
    array $audits) {
    assert_instances_of($audits, 'PhabricatorRepositoryAuditRequest');
    $user = $this->getRequest()->getUser();

    $view = new PhabricatorAuditListView();
    $view->setAudits($audits);
    $view->setCommits(array($commit));
    $view->setUser($user);
    $view->setShowDescriptions(false);

    $phids = $view->getRequiredHandlePHIDs();
    $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();
    $view->setHandles($handles);
    $view->setAuthorityPHIDs($this->auditAuthorityPHIDs);

    $panel = new AphrontPanelView();
    $panel->setHeader('Audits');
    $panel->setCaption('Audits you are responsible for are highlighted.');
    $panel->appendChild($view);

    return $panel;
  }

  private function buildComments(PhabricatorRepositoryCommit $commit) {
    $user = $this->getRequest()->getUser();
    $comments = id(new PhabricatorAuditComment())->loadAllWhere(
      'targetPHID = %s ORDER BY dateCreated ASC',
      $commit->getPHID());

    $inlines = id(new PhabricatorAuditInlineComment())->loadAllWhere(
      'commitPHID = %s AND auditCommentID IS NOT NULL',
      $commit->getPHID());

    $path_ids = mpull($inlines, 'getPathID');

    $path_map = array();
    if ($path_ids) {
      $path_map = id(new DiffusionPathQuery())
        ->withPathIDs($path_ids)
        ->execute();
      $path_map = ipull($path_map, 'path', 'id');
    }

    $view = new DiffusionCommentListView();
    $view->setUser($user);
    $view->setComments($comments);
    $view->setInlineComments($inlines);
    $view->setPathMap($path_map);

    $phids = $view->getRequiredHandlePHIDs();
    $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();
    $view->setHandles($handles);

    return $view;
  }

  private function buildAddCommentView(
    PhabricatorRepositoryCommit $commit,
    array $audit_requests) {
    assert_instances_of($audit_requests, 'PhabricatorRepositoryAuditRequest');
    $user = $this->getRequest()->getUser();

    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    Javelin::initBehavior(
      'differential-keyboard-navigation',
      array(
        // TODO: Make this comment panel hauntable
        'haunt' => null,
      ));

    $draft = id(new PhabricatorDraft())->loadOneWhere(
      'authorPHID = %s AND draftKey = %s',
      $user->getPHID(),
      'diffusion-audit-'.$commit->getID());
    if ($draft) {
      $draft = $draft->getDraft();
    } else {
      $draft = null;
    }

    $actions = $this->getAuditActions($commit, $audit_requests);

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->setAction('/audit/addcomment/')
      ->addHiddenInput('commit', $commit->getPHID())
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel('Action')
          ->setName('action')
          ->setID('audit-action')
          ->setOptions($actions))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel('Add Auditors')
          ->setName('auditors')
          ->setControlID('add-auditors')
          ->setControlStyle('display: none')
          ->setID('add-auditors-tokenizer')
          ->setDisableBehavior(true))
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setLabel('Add CCs')
          ->setName('ccs')
          ->setControlID('add-ccs')
          ->setControlStyle('display: none')
          ->setID('add-ccs-tokenizer')
          ->setDisableBehavior(true))
      ->appendChild(
        id(new AphrontFormTextAreaControl())
          ->setLabel('Comments')
          ->setName('content')
          ->setValue($draft)
          ->setID('audit-content')
          ->setCaption(phutil_render_tag(
            'a',
            array(
              'href' => PhabricatorEnv::getDoclink(
                'article/Remarkup_Reference.html'),
              'tabindex' => '-1',
              'target' => '_blank',
            ),
            'Formatting Reference')))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue($is_serious ? 'Submit' : 'Cook the Books'));

    $panel = new AphrontPanelView();
    $panel->setHeader($is_serious ? 'Audit Commit' : 'Creative Accounting');
    $panel->appendChild($form);

    require_celerity_resource('phabricator-transaction-view-css');

    Javelin::initBehavior(
      'differential-add-reviewers-and-ccs',
      array(
        'dynamic' => array(
          'add-auditors-tokenizer' => array(
            'actions' => array('add_auditors' => 1),
            'src' => '/typeahead/common/users/',
            'row' => 'add-auditors',
            'ondemand' => PhabricatorEnv::getEnvConfig('tokenizer.ondemand'),
            'placeholder' => 'Type a user name...',
          ),
          'add-ccs-tokenizer' => array(
            'actions' => array('add_ccs' => 1),
            'src' => '/typeahead/common/mailable/',
            'row' => 'add-ccs',
            'ondemand' => PhabricatorEnv::getEnvConfig('tokenizer.ondemand'),
            'placeholder' => 'Type a user or mailing list...',
          ),
        ),
        'select' => 'audit-action',
      ));

    Javelin::initBehavior('differential-feedback-preview', array(
      'uri'       => '/audit/preview/'.$commit->getID().'/',
      'preview'   => 'audit-preview',
      'content'   => 'audit-content',
      'action'    => 'audit-action',
      'previewTokenizers' => array(
        'auditors' => 'add-auditors-tokenizer',
        'ccs'      => 'add-ccs-tokenizer',
      ),
    ));

    $preview_panel =
      '<div class="aphront-panel-preview">
        <div id="audit-preview">
          <div class="aphront-panel-preview-loading-text">
            Loading preview...
          </div>
        </div>
      </div>';

    $view = new AphrontNullView();
    $view->appendChild($panel);
    $view->appendChild($preview_panel);
    return $view;
  }


  /**
   * Return a map of available audit actions for rendering into a <select />.
   * This shows the user valid actions, and does not show nonsense/invalid
   * actions (like closing an already-closed commit, or resigning from a commit
   * you have no association with).
   */
  private function getAuditActions(
    PhabricatorRepositoryCommit $commit,
    array $audit_requests) {
    assert_instances_of($audit_requests, 'PhabricatorRepositoryAuditRequest');
    $user = $this->getRequest()->getUser();

    $user_is_author = ($commit->getAuthorPHID() == $user->getPHID());

    $user_request = null;
    foreach ($audit_requests as $audit_request) {
      if ($audit_request->getAuditorPHID() == $user->getPHID()) {
        $user_request = $audit_request;
        break;
      }
    }

    $actions = array();
    $actions[PhabricatorAuditActionConstants::COMMENT] = true;
    $actions[PhabricatorAuditActionConstants::ADD_CCS] = true;
    $actions[PhabricatorAuditActionConstants::ADD_AUDITORS] = true;

    // We allow you to accept your own commits. A use case here is that you
    // notice an issue with your own commit and "Raise Concern" as an indicator
    // to other auditors that you're on top of the issue, then later resolve it
    // and "Accept". You can not accept on behalf of projects or packages,
    // however.
    $actions[PhabricatorAuditActionConstants::ACCEPT]  = true;
    $actions[PhabricatorAuditActionConstants::CONCERN] = true;


    // To resign, a user must have authority on some request and not be the
    // commit's author.
    if (!$user_is_author) {
      $may_resign = false;
      foreach ($audit_requests as $request) {
        if (empty($this->auditAuthorityPHIDs[$request->getAuditorPHID()])) {
          continue;
        }
        $may_resign = true;
        break;
      }

      // If the user has already resigned, don't show "Resign...".
      $status_resigned = PhabricatorAuditStatusConstants::RESIGNED;
      if ($user_request) {
        if ($user_request->getAuditStatus() == $status_resigned) {
          $may_resign = false;
        }
      }

      if ($may_resign) {
        $actions[PhabricatorAuditActionConstants::RESIGN] = true;
      }
    }

    $status_concern = PhabricatorAuditCommitStatusConstants::CONCERN_RAISED;
    $concern_raised = ($commit->getAuditStatus() == $status_concern);

    if ($user_is_author && $concern_raised) {
      $actions[PhabricatorAuditActionConstants::CLOSE] = true;
    }

    foreach ($actions as $constant => $ignored) {
      $actions[$constant] =
        PhabricatorAuditActionConstants::getActionName($constant);
    }

    return $actions;
  }

  private function buildMergesTable(PhabricatorRepositoryCommit $commit) {
    $drequest = $this->getDiffusionRequest();

    $limit = 50;

    $merge_query = DiffusionMergedCommitsQuery::newFromDiffusionRequest(
      $drequest);
    $merge_query->setLimit($limit + 1);
    $merges = $merge_query->loadMergedCommits();

    if (!$merges) {
      return null;
    }

    $caption = null;
    if (count($merges) > $limit) {
      $merges = array_slice($merges, 0, $limit);
      $caption =
        "This commit merges more than {$limit} changes. Only the first ".
        "{$limit} are shown.";
    }

    $history_table = new DiffusionHistoryTableView();
    $history_table->setDiffusionRequest($drequest);
    $history_table->setHistory($merges);

    $phids = $history_table->getRequiredHandlePHIDs();
    $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();
    $history_table->setHandles($handles);

    $panel = new AphrontPanelView();
    $panel->setHeader('Merged Changes');
    $panel->setCaption($caption);
    $panel->appendChild($history_table);

    return $panel;
  }

  private function renderHeadsupActionList(
    PhabricatorRepositoryCommit $commit) {

    $request = $this->getRequest();
    $user = $request->getUser();

    $actions = array();

    require_celerity_resource('phabricator-flag-css');
    $flag = PhabricatorFlagQuery::loadUserFlag($user, $commit->getPHID());
    if ($flag) {
      $class = PhabricatorFlagColor::getCSSClass($flag->getColor());
      $color = PhabricatorFlagColor::getColorName($flag->getColor());

      $action = new AphrontHeadsupActionView();
      $action->setClass('flag-clear '.$class);
      $action->setURI('/flag/delete/'.$flag->getID().'/');
      $action->setName('Remove '.$color.' Flag');
      $action->setWorkflow(true);
      $actions[] = $action;
    } else {
      $action = new AphrontHeadsupActionView();
      $action->setClass('phabricator-flag-ghost');
      $action->setURI('/flag/edit/'.$commit->getPHID().'/');
      $action->setName('Flag Commit');
      $action->setWorkflow(true);
      $actions[] = $action;
    }

    require_celerity_resource('phabricator-object-selector-css');
    require_celerity_resource('javelin-behavior-phabricator-object-selector');

    $action = new AphrontHeadsupActionView();
    $action->setName('Edit Maniphest Tasks');
    $action->setURI('/search/attach/'.$commit->getPHID().'/TASK/edge/');
    $action->setWorkflow(true);
    $action->setClass('attach-maniphest');
    $actions[] = $action;

    if ($user->getIsAdmin()) {
      $action = new AphrontHeadsupActionView();
      $action->setName('MetaMTA Transcripts');
      $action->setURI('/mail/?phid='.$commit->getPHID());
      $action->setClass('transcripts-metamta');
      $actions[] = $action;
    }

    $action = new AphrontHeadsupActionView();
    $action->setName('Herald Transcripts');
    $action->setURI('/herald/transcript/?phid='.$commit->getPHID());
    $action->setClass('transcripts-herald');
    $actions[] = $action;

    $action = new AphrontHeadsupActionView();
    $action->setName('Download Raw Diff');
    $action->setURI($request->getRequestURI()->alter('diff', true));
    $action->setClass('action-download');
    $actions[] = $action;

    $action_list = new AphrontHeadsupActionListView();
    $action_list->setActions($actions);

    return $action_list;
  }

  private function buildTags(DiffusionRequest $request) {
    $tag_limit = 10;

    $tag_query = DiffusionCommitTagsQuery::newFromDiffusionRequest($request);
    $tag_query->setLimit($tag_limit + 1);
    $tags = $tag_query->loadTags();

    if (!$tags) {
      return null;
    }

    $has_more_tags = (count($tags) > $tag_limit);
    $tags = array_slice($tags, 0, $tag_limit);

    $tag_links = array();
    foreach ($tags as $tag) {
      $tag_links[] = phutil_render_tag(
        'a',
        array(
          'href' => $request->generateURI(
            array(
              'action'  => 'browse',
              'commit'  => $tag->getName(),
            )),
        ),
        phutil_escape_html($tag->getName()));
    }

    if ($has_more_tags) {
      $tag_links[] = phutil_render_tag(
        'a',
        array(
          'href' => $request->generateURI(
            array(
              'action'  => 'tags',
            )),
        ),
        "More tags\xE2\x80\xA6");
    }

    $tag_links = implode(', ', $tag_links);

    return $tag_links;
  }

  private function buildRefs(DiffusionRequest $request) {
    // Not turning this into a proper Query class since it's pretty simple,
    // one-off, and Git-specific.

    $type_git = PhabricatorRepositoryType::REPOSITORY_TYPE_GIT;

    $repository = $request->getRepository();
    if ($repository->getVersionControlSystem() != $type_git) {
      return null;
    }

    list($stdout) = $repository->execxLocalCommand(
      'log --format=%s -n 1 %s --',
      '%d',
      $request->getCommit());

    return trim($stdout, "() \n");
  }

  private function buildRawDiffResponse(DiffusionRequest $drequest) {
    $raw_query = DiffusionRawDiffQuery::newFromDiffusionRequest($drequest);
    $raw_diff  = $raw_query->loadRawDiff();

    $hash = PhabricatorHash::digest($raw_diff);

    $file = id(new PhabricatorFile())->loadOneWhere(
      'contentHash = %s LIMIT 1',
      $hash);
    if (!$file) {
      // We're just caching the data; this is always safe.
      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

      $file = PhabricatorFile::newFromFileData(
        $raw_diff,
        array(
          'name' => $drequest->getCommit().'.diff',
        ));

      unset($unguarded);
    }

    return id(new AphrontRedirectResponse())->setURI($file->getBestURI());
  }

}
