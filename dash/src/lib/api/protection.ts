import { api } from './client';
import type { ProtectionIntentMutationResult, ProtectionIntentPreview, ProtectionIntentSummary, ProtectionProfileMutationResult, ProtectionProfilePreview, ProtectionProfileSummary } from '@/types';

export const protectionApi = {
  listProfiles: (domainId: string) => api.get<ProtectionProfileSummary[]>(`/api/v1/domains/${domainId}/protection/profiles`),
  previewProfile: (domainId: string, profileKey: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionProfilePreview>(`/api/v1/domains/${domainId}/protection/profiles/${profileKey}/preview`, input),
  applyProfile: (domainId: string, profileKey: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionProfileMutationResult>(`/api/v1/domains/${domainId}/protection/profiles/${profileKey}/apply`, input),
  disableProfile: (domainId: string, profileId: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionProfileMutationResult>(`/api/v1/domains/${domainId}/protection/profiles/${profileId}/disable`, input),
  listIntents: (domainId: string) => api.get<ProtectionIntentSummary[]>(`/api/v1/domains/${domainId}/protection/intents`),
  previewIntent: (domainId: string, intentKey: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionIntentPreview>(`/api/v1/domains/${domainId}/protection/intents/${intentKey}/preview`, input),
  enableIntent: (domainId: string, intentKey: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionIntentMutationResult>(`/api/v1/domains/${domainId}/protection/intents/${intentKey}/enable`, input),
  disableIntent: (domainId: string, intentId: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionIntentMutationResult>(`/api/v1/domains/${domainId}/protection/intents/${intentId}/disable`, input),
  undoIntent: (domainId: string, intentId: string) =>
    api.post<ProtectionIntentMutationResult>(`/api/v1/domains/${domainId}/protection/intents/${intentId}/undo`),
};
