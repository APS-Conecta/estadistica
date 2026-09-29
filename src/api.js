// The per-app binding of the shared error shape (aps-common js/api.js, synced
// into src/aps by `make aps-sync`): one prefix, never the raw exception.
import { messageFrom, loggers } from './aps/api.js'

const { safeError, warnError } = loggers('estadistica')

export { messageFrom, safeError, warnError }
