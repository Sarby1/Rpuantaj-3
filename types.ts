
export interface Employee {
  id: string;
  name: string;
  hourlyRate: number;
}

export interface AttendanceRecord {
  id: string;
  employeeId: string;
  date: string; // ISO Date YYYY-MM-DD
  startTime: string; // HH:mm
  endTime: string; // HH:mm
  calculatedHours: number;
  totalEarning: number;
}
