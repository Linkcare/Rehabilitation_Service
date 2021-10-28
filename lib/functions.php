<?php

/**
 * Generate a summary of the exercices performed by a patient in a date range
 * The summary is stored in the FORM as an array where each row correponds to a date, and the columns correspond to the exercises performed.
 *
 * @param string $admissionId
 * @param string $summaryFormId
 * @param string $fromDate
 * @param string $toDate
 * @return string[]
 */
function trainingSummary($admissionId, $summaryFormId, $fromDate, $toDate) {
    $api = LinkcareSoapAPI::getInstance();

    log_trace("GENERATE TRAINING . Date: $toDate,  Admission: $admissionId");

    $admission = $api->admission_get($admissionId);
    $programCode = $admission->getSubscription()->getProgram()->getCode();
    $targetForm = $api->form_get_summary($summaryFormId);

    // Search the training TASKS in the date range specified
    $filter = new TaskFilter();
    $filter->setObjectType('TASKS');
    $filter->setFromDate($fromDate);
    $filter->setToDate($toDate);
    $filter->setTaskCodes($GLOBALS['TASK_CODES']['TRAINING_EXERCISES']);
    $trainingTasks = $api->admission_get_task_list($admissionId, 1000, 0, $filter, false);

    $summary = [];
    foreach ($trainingTasks as $dayTraining) {
        // Search the FORM that contains the summary of the exercices performed in one day
        $date = explode(' ', $dayTraining->getDate())[0];
        $summary[$date] = extractDayTrainingData($dayTraining, $programCode);
    }

    ksort($summary);

    updateTrainingSummary($targetForm, $summary);

    return ['ErrorMsg' => '', 'ErrorCode' => ''];
}

/**
 *
 * @param APITask $dayTraining
 */
function extractDayTrainingData($dayTraining, $programCode) {
    $summaryForm = $dayTraining->findForm($GLOBALS['FORM_CODES']['SUMMARY']);
    $trainingResult = [];
    if ($summaryForm) {
        foreach ($summaryForm->getQuestions() as $q) {
            if (!$q->getItemCode()) {
                continue;
            }
            // The value assigned to each training will be an OBJECT CODE expression, because we will store it in an ITEM of type "EVALUATION"
            $trainingResult[$q->getItemCode()] = 'PROGRAM{' . $programCode . '}.FORM{' . $summaryForm->getId() . '}.ITEM{' . $q->getItemCode() .
                    '}.ANSWER{DESC}';
        }
    }

    return $trainingResult;
}

/**
 * Updates the number of steps in the TASK provided.
 * The parameter $partialSteps can be used to store also a breakdown of the steps in different intervals of the day. It must be an array where each
 * item is an associative array with 2 values:
 * <ul>
 * <li>'[{ "time": "00:00:00", "value": 0 },</li>
 * <li>'{ "time": "00:01:00", "value": 287 },</li>
 * <li>'{ "time": "00:02:00", "value": 287 }]</li>
 * </ul>
 *
 * @param APIForm $targetForm
 * @param string[] $summary
 * @throws APIException
 */
function updateTrainingSummary($targetForm, $summary) {
    if (!$targetForm) {
        return;
    }

    $api = LinkcareSoapAPI::getInstance();
    $arrQuestions = [];
    /* ARRAY EXERCISES */
    if (($arrayFirstQuestion = $targetForm->findQuestion($GLOBALS['ITEM_CODES']['ARRAY_EXERCISES'])) && $arrayFirstQuestion->getArrayRef()) {
        $row = 1;
        foreach ($summary as $date => $dayInfo) {
            if ($dateQuestion = $targetForm->findArrayQuestion($arrayFirstQuestion->getArrayRef(), $row, $GLOBALS['ITEM_CODES']['ARRAY_EXERCISES'])) {
                $dateQuestion->setValue($date);
            }
            $rowQuestions = [];
            foreach ($dayInfo as $exerciseName => $value) {
                if (startsWith('ESTIRAMIENTO', $exerciseName)) {
                    continue;
                }
                if ($question = $targetForm->findArrayQuestion($arrayFirstQuestion->getArrayRef(), $row, $exerciseName)) {
                    $question->setValue($value);
                    $rowQuestions[] = $question;
                }
            }
            if (empty($rowQuestions)) {
                continue;
            }
            $arrQuestions = array_merge($arrQuestions, [$dateQuestion], $rowQuestions);
            $row++;
        }
    }

    /* ARRAY STRETCHING */
    if (($arrayFirstQuestion = $targetForm->findQuestion($GLOBALS['ITEM_CODES']['ARRAY_STRETCHING'])) && $arrayFirstQuestion->getArrayRef()) {
        $row = 1;
        foreach ($summary as $date => $dayInfo) {
            if ($dateQuestion = $targetForm->findArrayQuestion($arrayFirstQuestion->getArrayRef(), $row, $GLOBALS['ITEM_CODES']['ARRAY_STRETCHING'])) {
                $dateQuestion->setValue($date);
            }
            $rowQuestions = [];
            foreach ($dayInfo as $exerciseName => $value) {
                if (!startsWith('ESTIRAMIENTO', $exerciseName)) {
                    continue;
                }
                if ($question = $targetForm->findArrayQuestion($arrayFirstQuestion->getArrayRef(), $row, $exerciseName)) {
                    $question->setValue($value);
                    $rowQuestions[] = $question;
                }
            }
            if (empty($rowQuestions)) {
                continue;
            }
            $arrQuestions = array_merge($arrQuestions, [$dateQuestion], $rowQuestions);
            $row++;
        }
    }

    if (!empty($arrQuestions)) {
        $api->form_set_all_answers($targetForm->getId(), $arrQuestions, false);
    }
}

/**
 * Connects to the WS-API using the session $token passed as parameter
 *
 * @param string $token
 * @param string $user
 * @param string $password
 * @param int $role
 * @param string $team
 *
 * @throws APIException
 * @throws Exception
 * @return LinkcareSoapAPI
 */
function apiConnect($token, $user = null, $password = null, $role = null, $team = null, $reuseExistingSession = false) {
    $timezone = "0";
    $session = null;

    try {
        LinkcareSoapAPI::setEndpoint($GLOBALS["WS_LINK"]);
        if ($token) {
            LinkcareSoapAPI::session_join($token, $timezone);
        } else {
            LinkcareSoapAPI::session_init($user, $password, 0, $reuseExistingSession);
        }

        $session = LinkcareSoapAPI::getInstance()->getSession();
        // Ensure to set the correct active ROLE and TEAM
        if ($team && $team != $session->getTeamCode() && $team != $session->getTeamId()) {
            LinkcareSoapAPI::getInstance()->session_set_team($team);
        }
        if ($role && $session->getRoleId() != $role) {
            LinkcareSoapAPI::getInstance()->session_role($role);
        }
    } catch (APIException $e) {
        throw $e;
    } catch (Exception $e) {
        throw $e;
    }

    return $session;
}
