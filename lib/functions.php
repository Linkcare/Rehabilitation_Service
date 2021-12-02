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

// Constants to define the performance of a patient doing the planned exercises
const PERFORMANCE_NO_DATA = 'NO_DATA';
const PERFORMANCE_OK = 'OK';
const PERFORMANCE_OK1 = 'OK1';
const PERFORMANCE_OK2 = 'OK2';
const PERFORMANCE_OK3 = 'OK3';
const PERFORMANCE_ONE_PENDING = 'ONE_PENDING';
const PERFORMANCE_KO = 'KO';
const PERFORMANCE_EMPTY = '';

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
 * @param string $admissionId
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
 * Calculates how the patient is performing his exercises in a date range.
 * The function returns 2 values:
 * <ol>
 * <li>Performance: It is calculated based on the number of completed, expired and pending exercise sessions. Can be one of the following values:
 * <ul>
 * <li>NO_DATA: When there are no exercise sessions in the date range</li>
 * <li>OK: When expired = 0, Closed >= 1, Pending = 0</li>
 * <li>OK1: When expired = 0, Closed >= 1, Pending = 1</li>
 * <li>OK2: When expired >= 1, Closed >= 1, Pending = 1</li>
 * <li>OK3: When expired >= 1, Closed >= 1, Pending = 0</li>
 * <li>ONE_PENDING: When expired = 0, Closed = 0, Pending = 1</li>
 * <li>KO: When expired >= 1, Closed = 0, Pending = 1</li>
 * <li>(empty): When expired >= 1, Closed = 0, Pending = 0 (all expired)</li>
 * </ul>
 * </li>
 * <li>Difficulty: Indicates if the patient foud it difficult to do any of the exercises
 * <ul>
 * <li>0: Not difficult</li>
 * <li>1: Difficult</li>
 * </ul>
 * </li>
 * </ol>
 *
 * @param string $admissionId
 * @param string $fromDate
 * @param string $toDate
 * @return string[]
 */
function calculatePerformance($admissionId, $fromDate, $toDate) {
    $api = LinkcareSoapAPI::getInstance();

    log_trace("CALCULATE COMPLIANCE . Date: $toDate,  Admission: $admissionId");

    $admission = $api->admission_get($admissionId);

    // Search the training TASKS in the date range specified
    $filter = new TaskFilter();
    $filter->setObjectType('TASKS');
    $filter->setFromDate($fromDate);
    $filter->setToDate($toDate);
    $filter->setTaskCodes($GLOBALS['TASK_CODES']['TRAINING_EXERCISES']);
    // Find the 2 last exercise sessions
    $trainingTasks = $admission->getTaskList(25, 0, $filter, false);
    if (count($trainingTasks) == 0) {
        $result = ['performance' => PERFORMANCE_NO_DATA, 'difficulty' => ''];
        return ['result' => json_encode($result), 'ErrorMsg' => '', 'ErrorCode' => ''];
    }

    $effort = EFFORT_UNKNOWN;
    $closed = 0;
    $pending = 0;
    $expired = 0;
    foreach ($trainingTasks as $t) {
        if ($t->isCancelled()) {
            continue;
        }
        if ($effort < EFFORT_HIGH) {
            /* If at least one exercise has been found to be difficult there is no need to examine the effort in the rest of TASKs */
            $e = extractTrainingEffort($t);
            if ($e > $effort) {
                $effort = $e;
            }
        }
        $closed += $t->isClosed() ? 1 : 0;
        $pending += $t->isOpen() ? 1 : 0;
        $expired += $t->isExpired() ? 1 : 0;
    }

    $perf = PERFORMANCE_NO_DATA;
    if ($expired == 0 && $closed >= 1 && $pending == 0) {
        $perf = PERFORMANCE_OK;
    } elseif ($expired == 0 && $closed >= 1 && $pending == 1) {
        $perf = PERFORMANCE_OK1;
    } elseif ($expired >= 1 && $closed >= 1 && $pending == 1) {
        $perf = PERFORMANCE_OK2;
    } elseif ($expired >= 1 && $closed >= 1 && $pending == 0) {
        $perf = PERFORMANCE_OK3;
    } elseif ($expired == 0 && $closed == 0 && $pending == 1) {
        $perf = PERFORMANCE_ONE_PENDING;
    } elseif ($expired >= 1 && $closed == 0 && $pending == 1) {
        $perf = PERFORMANCE_KO;
    } elseif ($expired >= 1 && $closed == 0 && $pending == 0) {
        $perf = PERFORMANCE_EMPTY;
    }

    $result = ['performance' => $perf, 'difficulty' => ($effort < EFFORT_HIGH ? 0 : 1)];
    return ['result' => json_encode($result), 'ErrorMsg' => '', 'ErrorCode' => ''];
}

/* ************************************* INTERNAL FUNCTIONS ************************************* */

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
