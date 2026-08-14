import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'

const unitClass = 'h-11 rounded-lg border border-border bg-background px-2 text-sm'

export default function WorkflowStepTimingFields({ step, onChange, disabled = false }) {
  return (
    <div className="mt-3 grid gap-3 sm:grid-cols-2">
      <div>
        <Label>Durée de l’étape</Label>
        <div className="mt-1 flex gap-2">
          <Input
            type="number"
            min={1}
            disabled={disabled}
            className="h-11"
            value={step.duration_amount}
            onChange={(e) => onChange({ duration_amount: e.target.value })}
            placeholder="—"
          />
          <select
            disabled={disabled}
            className={unitClass}
            value={step.duration_unit}
            onChange={(e) => onChange({ duration_unit: e.target.value })}
          >
            <option value="hours">heures</option>
            <option value="days">jours</option>
          </select>
        </div>
      </div>
      <div>
        <Label>Rappel avant échéance</Label>
        <div className="mt-1 flex gap-2">
          <Input
            type="number"
            min={1}
            disabled={disabled}
            className="h-11"
            value={step.reminder_amount}
            onChange={(e) => onChange({ reminder_amount: e.target.value })}
            placeholder="—"
          />
          <select
            disabled={disabled}
            className={unitClass}
            value={step.reminder_unit}
            onChange={(e) => onChange({ reminder_unit: e.target.value })}
          >
            <option value="hours">heures</option>
            <option value="days">jours</option>
          </select>
        </div>
      </div>
      <p className="sm:col-span-2 text-xs text-muted-foreground">
        Si une durée est définie, les responsables sont prévenus avant l’échéance, puis automatiquement en cas de dépassement.
      </p>
    </div>
  )
}
