<?php
// Constants to define the compliance of a patient
const COMPLIANCE_NOT_ENOUGH_DATA = 0;
const COMPLIANCE_GREEN = 1;
const COMPLIANCE_YELLOW = 2;
const COMPLIANCE_RED = 3;

// Constants to define the effort employed to do an execrice
const EFFORT_UNKNOWN = 0;
const EFFORT_LOW = 1;
const EFFORT_MODERATE = 2;
const EFFORT_HIGH = 3;
const EFFORT_VERY_HIGH = 4;

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
 * Calculate the compliance of a patient in the Digital Trainer PROGRAM.
 * This function checks whether the patient is executing the scheduled exercices and how well is doing.<br>
 * The return value is a number:
 * <ul>
 * <li>0: Not enough information to calculate compliance</li>
 * <li>1 (green): The last scheduled exercise was done without difficulty</li>
 * <li>3 (red): The patient is not doing well. One of the following situations is happening:
 * <ul>
 * <li>The last scheduled training exercises is not complete and the penultimate is expired</li>
 * <li>The last scheduled training exercises has been completed but with very high effort</li>
 * </ul>
 * </li>
 * <li>2 (yellow): Any other case</li>
 * </ul>
 *
 * @param string $admission
 * @param string $date
 * @return string[]
 */
function calculateCompliance($admissionId, $date) {
    $api = LinkcareSoapAPI::getInstance();

    log_trace("CALCULATE COMPLIANCE . Date: $date,  Admission: $admissionId");

    $admission = $api->admission_get($admissionId);

    // Search the training TASKS in the date range specified
    $filter = new TaskFilter();
    $filter->setObjectType('TASKS');
    $filter->setToDate($date);
    $filter->setTaskCodes($GLOBALS['TASK_CODES']['TRAINING_EXERCISES']);
    // Find the 2 last exercise sessions
    $trainingTasks = $admission->getTaskList(2, 0, $filter, false);
    if (count($trainingTasks) == 0) {
        return ['result' => COMPLIANCE_NOT_ENOUGH_DATA, 'ErrorMsg' => '', 'ErrorCode' => ''];
    }

    $lastTrainingTask = $trainingTasks[0];
    $penultimateTrainingTask = count($trainingTasks) > 1 ? $trainingTasks[1] : null;

    $effort = EFFORT_UNKNOWN;
    if ($lastTrainingTask->isClosed()) {
        $effort = extractTrainingEffort($lastTrainingTask);
    }

    // GREEN COMPLIANCE
    if ($lastTrainingTask->isClosed() && $effort <= EFFORT_LOW) {
        // The last exercises session was completed with no effort
        return ['result' => COsMPLIANCE_GREEN, 'ErrorMsg' => '', 'ErrorCode' => ''];
    }

    // RED COMPLIANCE
    if ($lastTrainingTask->isClosed() && $effort >= EFFORT_VERY_HIGH) {
        // The last exercises session was completed with very high effort
        return ['result' => COMPLIANCE_RED, 'ErrorMsg' => '', 'ErrorCode' => ''];
    } elseif (($lastTrainingTask->isOpen() || $lastTrainingTask->isExpired()) && $penultimateTrainingTask && $penultimateTrainingTask->isExpired()) {
        // The last scheduled training exercises is not complete and the penultimate is expired
        return ['result' => COMPLIANCE_RED, 'ErrorMsg' => '', 'ErrorCode' => ''];
    }

    // YELLOW COMPLIANCE
    // If the compliance is not GREEN nor RED, it must be yellow

    return ['result' => COMPLIANCE_YELLOW, 'ErrorMsg' => '', 'ErrorCode' => ''];
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
 * Returns the maximum effort informed among all the exercises of a training session
 *
 * @param APITask $trainingTask
 * @return int
 */
function extractTrainingEffort($trainingTask) {
    $effort = EFFORT_UNKNOWN;
    foreach ($trainingTask->getForms() as $f) {
        // The FORMs with the information about the effort have a FORM_CODE like: DT_[exercise name]_CONTENT
        if (!preg_match('/^DT_.*_CONTENT$/', $f->getFormCode())) {
            continue;
        }
        if ($q = $f->findQuestion($GLOBALS['ITEM_CODES']['EFFORT'])) {
            if ($q->getValue() > $effort) {
                $effort = $q->getValue();
            }
        }
    }

    return $effort;
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
