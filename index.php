<?php

class TaskCalculate
{
    private function addWorkingHours(DateTime $start, $hoursToAdd, $workSchedule, $weeklyOffDays, $holidays)
    {
        $current = clone $start;
        $remainingHours = $hoursToAdd;

        while ($remainingHours > 0) {
            $today = $current->format("Y-m-d");
            $dayOfWeek = (int)$current->format("N");

            if (in_array($dayOfWeek, $weeklyOffDays) || in_array($today, $holidays)) {
                $current = new DateTime($today . " " . $workSchedule['start']);
                $current->modify("+1 day");
                continue;
            }

            $todayWorkEnd = new DateTime($today . " " . $workSchedule['end']);
            $available = ($todayWorkEnd->getTimestamp() - $current->getTimestamp()) / 3600;

            if ($remainingHours <= $available) {
                $secondsToAdd = round($remainingHours * 3600);
                $current->add(new DateInterval("PT{$secondsToAdd}S"));
                $remainingHours = 0;
            } else {
                $remainingHours -= $available;
                $current = new DateTime($today . " " . $workSchedule['start']);
                $current->modify("+1 day");
            }
        }

        return $current;
    }

    private function adjustToWorkingDay(DateTime $time, $workSchedule, $weeklyOffDays, $holidays)
    {
        $adjusted = clone $time;
        if ($adjusted->format("H:i") < $workSchedule['start']) {
            $adjusted->setTime((int)substr($workSchedule['start'], 0, 2), (int)substr($workSchedule['start'], 3, 2));
        }

        while (in_array($adjusted->format("N"), $weeklyOffDays) || in_array($adjusted->format("Y-m-d"), $holidays)) {
            $adjusted->modify("+1 day");
            $adjusted->setTime((int)substr($workSchedule['start'], 0, 2), (int)substr($workSchedule['start'], 3, 2));
        }

        return $adjusted;
    }

    public function insertNewTaskPeriod($userId = null, $insertStart = null, $insertedHours = null)
    {
        $userId = $userId ? $userId : $this->input->post("userId");
        $insertStart = $insertStart ? $insertStart : $this->input->post("insertStart"); // Örnek: "2025-03-03 08:00"
        $insertedHours = $insertedHours ? $insertedHours : $this->input->post("insertedHours");

        $this->load->model('ProjectsModel');
        $tasks = $this->ProjectsModel->GetTasksWithAvailableSlots($userId);

        $this->load->model('UsersModel');
        $user = $this->UsersModel->GetUser($userId);
        $workHours = json_decode($user['workHours']);
        $workDays = json_decode($user['workDays']);

        $workSchedule = ['start' => $workHours[0], 'end' => $workHours[1]];
        $weeklyOffDays = [];
        for ($i = 1; $i <= 7; $i++) {
            if (!in_array($i, $workDays)) {
                $weeklyOffDays[] = $i;
            }
        }
        $holidays = array();
        $insertStartDate = new DateTime($insertStart);
        $newTaskStart = clone $insertStartDate;
        $newTaskEnd = $this->addWorkingHours(clone $newTaskStart, $insertedHours, $workSchedule, $weeklyOffDays, $holidays);
        $newTask = [
            'start' => $newTaskStart->format("Y-m-d H:i"),
            'end' => $newTaskEnd->format("Y-m-d H:i"),
            'surec' => $insertedHours,
            'isNew' => true
        ];
        foreach ($tasks as &$task) {
            $taskStart = new DateTime($task['start']);
            $taskEnd = new DateTime($task['end']);
            if ($taskStart >= $insertStartDate) {
                $newTaskStartForTask = $this->addWorkingHours($taskStart, $insertedHours, $workSchedule, $weeklyOffDays, $holidays);
                $durationSeconds = $taskEnd->getTimestamp() - $taskStart->getTimestamp();
                $durationHours = $durationSeconds / 3600;
                $newTaskEndForTask = $this->addWorkingHours($newTaskStartForTask, $durationHours, $workSchedule, $weeklyOffDays, $holidays);

                $task['start'] = $newTaskStartForTask->format("Y-m-d H:i");
                $task['end'] = $newTaskEndForTask->format("Y-m-d H:i");
            }
        }
        unset($task);

        $tasks[] = $newTask;

        return $tasks;
        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['rescheduledTasks' => $tasks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
