import { api } from './client';
import type { ProtectionIntentMutationResult, ProtectionIntentPreview, ProtectionIntentSummary } from '@/types';

export const protectionApi = {
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
