<?php

namespace App\Services\Attendance;

abstract class AttendanceServiceSupport
{
    use AttendanceAlertRules;
    use AttendanceDailyEditRequestMapping;
    use AttendanceDailyHistoryRecorder;
    use AttendanceDailyMapping;
    use AttendanceErrorDetection;
    use AttendancePunchRebuilder;
    use AttendanceSharedUtilities;
    protected const MONTH_CLOSE_OPEN = 'OPEN';
    protected const MONTH_CLOSE_CLOSED = 'CLOSED';

}


