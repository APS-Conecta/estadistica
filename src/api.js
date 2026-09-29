// The per-app binding of the shared error shape (aps-common js/api.js, synced
// into src/aps by `make aps-sync`): one prefix, never the raw exception.
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { messageFrom, loggers } from './aps/api.js'

const { safeError, warnError } = loggers('estadistica')

/**
 * The page reads the same OCS surface suite apps and M3 read (ApiController),
 * so there is one set of numbers. @nextcloud/axios sends the session's
 * request token, which passes the CSRF check on an OCS route as on any other
 * (SecurityMiddleware), and axios's default Accept asks for JSON
 * (Request::getFormat). The payload sits inside the OCS envelope.
 */
const ocsUrl = (path) => generateOcsUrl(`/apps/estadistica/api/v1/${path}`)

export async function ocsGet(path, params = {}) {
	const { data } = await axios.get(ocsUrl(path), { params })
	return data.ocs.data
}

export async function ocsPost(path, body) {
	const { data } = await axios.post(ocsUrl(path), body)
	return data.ocs.data
}

/**
 * messageFrom, one envelope deeper: an OCS refusal carries its Spanish
 * sentence at ocs.data.message (ApiController::refuse).
 */
export function ocsMessageFrom(error, fallback) {
	return messageFrom({ response: { data: error?.response?.data?.ocs?.data } }, fallback)
}

export { messageFrom, safeError, warnError }
