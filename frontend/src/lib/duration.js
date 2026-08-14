export function hoursFromParts(amount, unit) {
  const n = Number(amount)
  if (!Number.isFinite(n) || n < 1) return null
  return unit === 'days' ? n * 24 : n
}

export function partsFromHours(hours, fallbackUnit = 'days') {
  if (hours == null || Number(hours) < 1) {
    return { amount: '', unit: fallbackUnit }
  }
  const h = Number(hours)
  if (h % 24 === 0) {
    return { amount: String(h / 24), unit: 'days' }
  }
  return { amount: String(h), unit: 'hours' }
}

export function stepTimingFromApi(step) {
  const duration = partsFromHours(step?.duration_hours)
  const reminder = partsFromHours(step?.reminder_hours_before, 'hours')
  return {
    duration_amount: duration.amount,
    duration_unit: duration.unit,
    reminder_amount: reminder.amount,
    reminder_unit: reminder.unit,
  }
}

export function emptyStepTiming() {
  return {
    duration_amount: '1',
    duration_unit: 'days',
    reminder_amount: '4',
    reminder_unit: 'hours',
  }
}

export function timingPayload(step) {
  const durationHours = hoursFromParts(step.duration_amount, step.duration_unit)
  const reminderHours = hoursFromParts(step.reminder_amount, step.reminder_unit)
  return {
    duration_hours: durationHours,
    reminder_hours_before: reminderHours,
    remind_on_overdue: true,
  }
}

export function validateStepTiming(step) {
  const durationHours = hoursFromParts(step.duration_amount, step.duration_unit)
  const reminderHours = hoursFromParts(step.reminder_amount, step.reminder_unit)
  if (reminderHours && !durationHours) {
    return 'Indiquez une durée d’étape pour planifier un rappel.'
  }
  if (reminderHours && durationHours && reminderHours >= durationHours) {
    return 'Le rappel doit se déclencher avant la fin de la durée de l’étape.'
  }
  return null
}
