import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { createRoot } from 'react-dom/client';
import {
  Button,
  ButtonGroup,
  Drawer,
  FormFieldPassword,
  FormFieldText,
  Icon,
  Link,
  List,
  ListEmptyView,
  Pagination,
  Progress,
  ProgressStep,
  SearchBar,
  Status,
  Switch,
  Toaster,
} from '@plesk/ui-library';
import '@plesk/ui-library/dist/plesk-ui-library.css';

async function postAction(action, data) {
  const protectionToken = getForgeryProtectionToken();
  const body = new URLSearchParams();
  body.set('forgery_protection_token', protectionToken);

  Object.entries(data).forEach(([key, value]) => {
    body.set(key, value);
  });

  const response = await fetch(action, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Forgery-Protection-Token': protectionToken,
    },
    body,
  });

  const contentType = response.headers.get('content-type') || '';
  const text = await response.text();
  let payload = null;

  try {
    payload = text ? JSON.parse(text) : null;
  } catch (error) {
    throw new Error(explainUnexpectedResponse(response, text, contentType));
  }

  if (!response.ok || !payload?.success) {
    const apiError = new Error(payload?.message || `Request failed. HTTP ${response.status}.`);
    apiError.payload = payload;
    throw apiError;
  }

  return payload;
}

function getForgeryProtectionToken() {
  const token = document
    .querySelector('meta[name="forgery_protection_token"]')
    ?.getAttribute('content');

  if (!token) {
    throw new Error('Plesk session token is missing. Refresh the page and try again.');
  }

  return token;
}

function explainUnexpectedResponse(response, text, contentType) {
  const plain = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  if (plain) {
    return `Server returned ${contentType || 'non-JSON'} response (HTTP ${response.status}): ${plain.slice(0, 180)}`;
  }

  return `Server returned an empty non-JSON response (HTTP ${response.status}).`;
}

function statusProps(status) {
  switch (status) {
    case 'active':
      return { intent: 'success', label: 'Active' };
    case 'warning':
      return { intent: 'warning', label: 'Warning' };
    case 'invalid':
      return { intent: 'danger', label: 'Invalid' };
    default:
      return { intent: 'inactive', label: 'Inactive' };
  }
}

function EmptyList({ title, description, actions }) {
  return (
    <List
      columns={[]}
      data={[]}
      emptyView={
        <ListEmptyView
          title={title}
          description={description}
          actions={actions}
        />
      }
    />
  );
}

function linkedStatus(status) {
  switch (status) {
    case 'active':
    case 'synced':
      return { intent: 'success', label: status === 'synced' ? 'Synced' : 'Linked' };
    case 'pending':
    case 'linked':
      return { intent: 'info', label: status === 'pending' ? 'Pending' : 'Linked' };
    case 'error':
      return { intent: 'danger', label: 'Error' };
    default:
      return { intent: 'inactive', label: status || 'Inactive' };
  }
}

function recordStatus(status) {
  switch (status) {
    case 'synced':
      return { intent: 'success', label: 'Synced' };
    case 'mismatch':
      return { intent: 'warning', label: 'Mismatch' };
    case 'cloudflare_only':
      return { intent: 'info', label: 'Cloudflare only' };
    default:
      return { intent: 'inactive', label: 'Not synced' };
  }
}

function RecordValue({ row }) {
  if (row.status === 'mismatch') {
    return (
      <div className="gc-record-values">
        <div className="gc-record-value-row">
          <Icon name="server" />
          <span>{'Plesk'}</span>
          <code>{row.local_content}</code>
        </div>
        <div className="gc-record-value-row">
          <Icon name="cloud" />
          <span>{'Cloudflare'}</span>
          <code>{row.cloudflare_content}</code>
        </div>
      </div>
    );
  }

  return (
    <code className="gc-record-single-value">
      {row.has_local ? row.local_content : row.cloudflare_content}
    </code>
  );
}

function appendParam(url, key, value) {
  const glue = url.includes('?') ? '&' : '?';
  return `${url}${glue}${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
}

function matchesSearch(item, query, fields) {
  const term = String(query || '').trim().toLowerCase();
  if (!term) {
    return true;
  }

  return fields.some(field => {
    const value = typeof field === 'function' ? field(item) : item[field];
    return String(value ?? '').toLowerCase().includes(term);
  });
}

function DomainApp({ syncAction, autosyncAction, recordsAction, initialDomains }) {
  const [domains, setDomains] = useState(initialDomains);
  const [toasts, setToasts] = useState([]);
  const [busy, setBusy] = useState('');
  const listTarget = document.getElementById('gc-domain-list');

  const notify = (intent, message) => {
    const key = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    setToasts(current => [
      { key, intent, message, autoClosable: intent === 'success', closable: true },
      ...current,
    ].slice(0, 5));
  };

  const syncDomain = async (domain, mode = 'sync') => {
    if (mode === 'import') {
      notify('info', 'Import is not enabled yet. This setup currently pushes Plesk DNS records to Cloudflare.');
      return;
    }

    setBusy(`${mode}-${domain.id}`);
    try {
      const payload = await postAction(syncAction, { link_id: domain.id, mode });
      setDomains(payload.domains || []);
      const created = payload.result?.created ?? 0;
      notify('success', `${mode === 'export' ? 'Exported' : 'Synced'} ${created} DNS record${created === 1 ? '' : 's'} to Cloudflare.`);
    } catch (error) {
      if (error.payload?.domains) {
        setDomains(error.payload.domains);
      }
      notify('danger', error.message);
    } finally {
      setBusy('');
    }
  };

  const toggleAutoSync = async (domain, value) => {
    const previous = domains;
    setDomains(current => current.map(item => (
      item.id === domain.id ? { ...item, auto_sync: value ? '1' : '0' } : item
    )));
    setBusy(`autosync-${domain.id}`);

    try {
      const payload = await postAction(autosyncAction, {
        link_id: domain.id,
        auto_sync: value ? '1' : '0',
      });
      setDomains(payload.domains || []);
      notify('success', value ? 'Auto Sync enabled.' : 'Auto Sync disabled.');
    } catch (error) {
      setDomains(previous);
      notify('danger', error.message);
    } finally {
      setBusy('');
    }
  };

  const syncAll = async () => {
    if (!domains.length) {
      return;
    }

    let current = domains;
    let ok = 0;
    let failed = 0;
    setBusy('sync-all');

    for (const domain of domains) {
      try {
        const payload = await postAction(syncAction, { link_id: domain.id });
        current = payload.domains || current;
        ok += 1;
      } catch (error) {
        failed += 1;
        if (error.payload?.domains) {
          current = error.payload.domains;
        }
      }
    }

    setDomains(current);
    setBusy('');
    if (failed) {
      notify('warning', `${ok} synced, ${failed} failed. Check API Logs for details.`);
    } else {
      notify('success', `${ok} linked domain${ok === 1 ? '' : 's'} synced.`);
    }
  };

  const columns = [
    {
      key: 'domain_name',
      title: 'Domain',
      type: 'title',
      width: '26%',
      truncate: true,
      render: row => <Link component="span">{row.domain_name}</Link>,
    },
    {
      key: 'token_name',
      title: 'Token',
      width: '18%',
      truncate: true,
    },
    {
      key: 'zone_name',
      title: 'Cloudflare zone',
      width: '22%',
      truncate: true,
    },
    {
      key: 'status',
      title: 'Status',
      width: '12%',
      type: 'controls',
      render: row => {
        const status = linkedStatus(row.status);
        return (
          <Status intent={status.intent} compact>
            {status.label}
          </Status>
        );
      },
    },
    {
      key: 'last_synced_at',
      title: 'Last synced',
      width: '14%',
      render: row => row.last_synced_at || '-',
    },
    {
      key: 'auto_sync',
      title: 'Auto Sync',
      width: '12%',
      type: 'controls',
      render: row => (
        <Switch
          checked={row.auto_sync === true || row.auto_sync === 1 || row.auto_sync === '1'}
          loading={busy === `autosync-${row.id}`}
          onChange={value => toggleAutoSync(row, value)}
          aria-label={`Auto Sync ${row.domain_name}`}
        />
      ),
    },
    {
      key: 'actions',
      title: 'Actions',
      type: 'actions',
      width: '18%',
      render: row => (
        <ButtonGroup>
          <Button
            icon="eye"
            arrow="backward"
            onClick={() => { window.location.href = appendParam(recordsAction, 'link_id', row.id); }}
          >
            {'View'}
          </Button>
          <Button
            icon="arrow-up-tray"
            intent="success"
            onClick={() => syncDomain(row, 'export')}
            state={busy === `export-${row.id}` ? 'loading' : undefined}
          >
            {'Export'}
          </Button>
          <Button
            icon="arrow-down-tray"
            intent="warning"
            onClick={() => syncDomain(row, 'import')}
          >
            {'Import'}
          </Button>
          <Button
            icon="reload"
            arrow="forward"
            intent="primary"
            onClick={() => syncDomain(row, 'sync')}
            state={busy === `sync-${row.id}` ? 'loading' : undefined}
          >
            {'Sync'}
          </Button>
        </ButtonGroup>
      ),
    },
  ];

  const data = domains.map(domain => ({
    ...domain,
    key: String(domain.id),
  }));

  return (
    <>
      <Toaster
        toasts={toasts}
        position="top-end"
        onToastClose={key => setToasts(current => current.filter(toast => toast.key !== key))}
      />
      {!!domains.length && (
        <Button
          arrow="forward"
          intent="primary"
          onClick={syncAll}
          state={busy === 'sync-all' ? 'loading' : undefined}
        >
          {'Sync All'}
        </Button>
      )}
      {listTarget &&
        createPortal(
          <List
            columns={columns}
            data={data}
            rowKey="key"
            emptyView={
              <ListEmptyView
                title="No linked Cloudflare domains yet"
                description="Add a valid token with access to zones matching your Plesk domains."
              />
            }
          />,
          listTarget
        )}
    </>
  );
}

function RecordsApp({ proxyAction, syncAction, startSyncJobAction, processSyncJobAction, syncJobStatusAction, recordAction, domain, initialRecords }) {
  const target = document.getElementById('gc-records-list');
  const [records, setRecords] = useState(initialRecords);
  const [toasts, setToasts] = useState([]);
  const [busy, setBusy] = useState('');
  const [sortDirection, setSortDirection] = useState('asc');
  const [page, setPage] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(10);
  const [searchQuery, setSearchQuery] = useState('');
  const syncToasterRef = useRef(null);
  const syncToastKeyRef = useRef(null);

  const notify = (intent, message) => {
    const key = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    setToasts(current => [
      { key, intent, message, autoClosable: intent === 'success', closable: true },
      ...current,
    ].slice(0, 5));
  };

  const modeLabel = mode => {
    switch (mode) {
      case 'import':
        return 'Import';
      case 'export':
        return 'Export';
      default:
        return 'Sync';
    }
  };

  const syncProgressMessage = job => {
    const progress = typeof job.progress === 'number' ? job.progress : -1;
    const isDone = job.status === 'done';
    const hasFailed = Boolean(job.failed);
    const label = modeLabel(job.mode);
    return (
      <Progress>
        <ProgressStep
          title={isDone ? `${label} completed` : `${label} in progress`}
          status={isDone ? (hasFailed ? 'warning' : 'done') : 'running'}
          progress={isDone ? 100 : progress}
          statusText={isDone ? 'Done' : `${progress >= 0 ? progress : 0}%`}
        >
          <span>
            {`${job.processed || 0} / ${job.total || 0} records`}
            <br />
            {`Created: ${job.created || 0}  Updated: ${job.updated || 0}  Failed: ${job.failed || 0}`}
          </span>
        </ProgressStep>
      </Progress>
    );
  };

  const showSyncProgress = job => {
    if (!syncToasterRef.current) {
      return;
    }

    const toast = {
      message: syncProgressMessage(job),
      closable: job.status === 'done',
    };

    if (syncToastKeyRef.current) {
      syncToasterRef.current.update(syncToastKeyRef.current, toast);
      return;
    }

    syncToastKeyRef.current = syncToasterRef.current.add(toast);
  };

  const processJob = async initialJob => {
    let job = initialJob;
    showSyncProgress(job);

    while (job && job.status !== 'done') {
      const payload = await postAction(processSyncJobAction, { job_id: job.id });
      job = payload.job;
      showSyncProgress(job);
      if (payload.records) {
        setRecords(payload.records);
      }
    }

    return job;
  };

  useEffect(() => {
    let cancelled = false;

    const resumeJob = async () => {
      if (!syncJobStatusAction || !processSyncJobAction) {
        return;
      }

      try {
        const payload = await postAction(syncJobStatusAction, { link_id: domain.id });
        if (!cancelled && payload.job) {
          setBusy(`sync-all-records-${payload.job.mode || 'sync'}`);
          const job = await processJob(payload.job);
          if (!cancelled) {
            if (job?.failed) {
              notify('warning', `${modeLabel(job.mode)} completed with ${job.failed} failed record${job.failed === 1 ? '' : 's'}.`);
            } else {
              notify('success', `${modeLabel(job?.mode)} completed.`);
            }
          }
        }
      } catch (error) {
        if (!cancelled) {
          notify('danger', error.message);
        }
      } finally {
        if (!cancelled) {
          setBusy('');
        }
      }
    };

    resumeJob();

    return () => {
      cancelled = true;
    };
  }, []);

  const toggleProxy = async (row, value) => {
    setBusy(row.cloudflare_id);
    try {
      const payload = await postAction(proxyAction, {
        link_id: domain.id,
        record_id: row.cloudflare_id,
        proxied: value ? '1' : '0',
      });
      setRecords(payload.records || []);
      notify('success', 'Proxy updated.');
    } catch (error) {
      notify('danger', error.message);
    } finally {
      setBusy('');
    }
  };

  const syncAllRecords = async (mode = 'sync') => {
    setBusy(`sync-all-records-${mode}`);
    showSyncProgress({
      status: 'starting',
      mode,
      total: 0,
      processed: 0,
      created: 0,
      updated: 0,
      failed: 0,
      progress: -1,
    });

    try {
      if (!startSyncJobAction || !processSyncJobAction) {
        throw new Error('Sync queue endpoints are missing. Refresh the page after updating the extension.');
      }

      const started = await postAction(startSyncJobAction || syncAction, { link_id: domain.id, mode });
      const job = await processJob(started.job);

      if (job?.failed) {
        notify('warning', `${modeLabel(job.mode)} completed with ${job.failed} failed record${job.failed === 1 ? '' : 's'}.`);
      } else {
        notify('success', `${modeLabel(job?.mode)} completed for ${job?.processed || 0} DNS record${job?.processed === 1 ? '' : 's'}.`);
      }
    } catch (error) {
      notify('danger', error.message);
    } finally {
      setBusy('');
    }
  };

  const runRecordAction = async (row, direction) => {
    const key = direction === 'pull' ? row.cloudflare_key : (row.local_key || row.cloudflare_key);
    if (direction === 'delete' && !window.confirm(`Delete DNS record "${row.name}" from Plesk and Cloudflare?`)) {
      return;
    }

    setBusy(`${direction}-${key}`);
    try {
      const payload = await postAction(recordAction, {
        link_id: domain.id,
        direction,
        record_key: key,
      });
      setRecords(payload.records || []);
      notify('success', direction === 'delete' ? 'Record deleted.' : (direction === 'pull' ? 'Record pulled.' : 'Record pushed.'));
    } catch (error) {
      notify('danger', error.message);
    } finally {
      setBusy('');
    }
  };

  const columns = [
    {
      key: 'type',
      title: (
        <Button
          ghost
          icon={sortDirection === 'asc' ? 'arrow-up' : 'arrow-down'}
          onClick={() => setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc')}
        >
          {'Type'}
        </Button>
      ),
      width: '8%',
      render: row => <Status intent="info" compact>{row.type}</Status>,
    },
    {
      key: 'name',
      title: 'Name',
      type: 'title',
      width: '22%',
      truncate: true,
      render: row => <Link component="span">{row.name}</Link>,
    },
    {
      key: 'status',
      title: 'Status',
      width: '13%',
      type: 'controls',
      render: row => {
        const status = recordStatus(row.status);
        return <Status intent={status.intent} compact>{status.label}</Status>;
      },
    },
    {
      key: 'record',
      title: 'Record',
      width: '42%',
      render: row => <RecordValue row={row} />,
    },
    {
      key: 'proxy',
      title: 'Proxy',
      width: '6%',
      render: row => {
        if (!row.can_proxy) {
          return '-';
        }
        return (
          <Switch
            checked={Boolean(row.cloudflare_proxied)}
            loading={busy === row.cloudflare_id}
            onChange={value => toggleProxy(row, value)}
            aria-label={`Proxy ${row.name}`}
          />
        );
      },
    },
    {
      key: 'actions',
      title: 'Actions',
      type: 'actions',
      width: '10%',
      render: row => (
        <ButtonGroup className="gc-record-actions">
          {row.status !== 'synced' && row.has_local ? (
            <Button
              icon="arrow-up-tray"
              arrow="backward"
              intent="success"
              title="Push"
              aria-label={`Push ${row.name}`}
              onClick={() => runRecordAction(row, 'push')}
              state={busy === `push-${row.local_key}` ? 'loading' : undefined}
            />
          ) : null}
          {row.status !== 'synced' && row.has_cloudflare ? (
            <Button
              icon="arrow-down-tray"
              intent="primary"
              title="Pull"
              aria-label={`Pull ${row.name}`}
              onClick={() => runRecordAction(row, 'pull')}
              state={busy === `pull-${row.cloudflare_key}` ? 'loading' : undefined}
            />
          ) : null}
          {(row.has_local || row.has_cloudflare) ? (
            <Button
              icon={<Icon name="recycle" intent="danger" />}
              arrow="forward"
              intent="danger"
              title="Delete"
              aria-label={`Delete ${row.name}`}
              onClick={() => runRecordAction(row, 'delete')}
              state={busy === `delete-${row.local_key || row.cloudflare_key}` ? 'loading' : undefined}
            />
          ) : null}
        </ButtonGroup>
      ),
    },
  ];

  const filteredRecords = records.filter(record => matchesSearch(record, searchQuery, [
    'type',
    'name',
    'status',
    'local_content',
    'cloudflare_content',
  ]));
  const sortedRecords = [...filteredRecords]
    .sort((left, right) => {
      const type = left.type.localeCompare(right.type);
      if (type !== 0) {
        return sortDirection === 'asc' ? type : -type;
      }

      return left.name.localeCompare(right.name);
    });
  const totalPages = itemsPerPage === 'all' ? 1 : Math.max(1, Math.ceil(sortedRecords.length / itemsPerPage));
  const currentPage = Math.min(page, totalPages);
  const visibleRecords = itemsPerPage === 'all'
    ? sortedRecords
    : sortedRecords.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);
  const data = visibleRecords
    .map((record, index) => ({
      ...record,
      key: `${record.type}-${record.name}-${(currentPage - 1) * (itemsPerPage === 'all' ? sortedRecords.length : itemsPerPage) + index}`,
    }));

  useEffect(() => {
    if (page > totalPages) {
      setPage(totalPages);
    }
  }, [page, totalPages]);

  const list = target ? createPortal(
    <>
      <List
        columns={columns}
        data={data}
        rowKey="key"
        totalRows={sortedRecords.length}
        className="gc-record-list"
        pagination={sortedRecords.length ? (
          <Pagination
            total={totalPages}
            current={currentPage}
            onSelect={setPage}
            itemsPerPage={itemsPerPage}
            itemsPerPageOptions={[10, 25, 50, 100, 'all']}
            onItemsPerPageChange={value => {
              setItemsPerPage(value);
              setPage(1);
            }}
          />
        ) : undefined}
        emptyView={
          <ListEmptyView
            title="No DNS records found"
            description="DNS records will appear here after they are available in Plesk or Cloudflare."
          />
        }
      />
    </>,
    target
  ) : null;

  return (
    <>
      <Toaster position="bottom-end" ref={syncToasterRef} />
      <Toaster
        toasts={toasts}
        position="top-end"
        onToastClose={key => setToasts(current => current.filter(toast => toast.key !== key))}
      />
      <div className="gc-list-toolbar">
        <SearchBar
          inputProps={{
            value: searchQuery,
            placeholder: 'Search records',
          }}
          onTyping={value => {
            setSearchQuery(value);
            setPage(1);
          }}
          onSearch={value => {
            setSearchQuery(value);
            setPage(1);
          }}
        />
      </div>
      <ButtonGroup>
        <Button
          arrow="backward"
          icon="arrow-left"
          onClick={() => window.history.back()}
        >
          {'Back'}
        </Button>
        <Button
          intent="warning"
          icon="arrow-down-tray"
          onClick={() => syncAllRecords('import')}
          state={busy === 'sync-all-records-import' ? 'loading' : undefined}
        >
          {'Import'}
        </Button>
        <Button
          intent="success"
          icon="arrow-up-tray"
          onClick={() => syncAllRecords('export')}
          state={busy === 'sync-all-records-export' ? 'loading' : undefined}
        >
          {'Export'}
        </Button>
        <Button
          arrow="forward"
          intent="primary"
          icon={<Icon name="reload" />}
          onClick={() => syncAllRecords('sync')}
          state={busy === 'sync-all-records-sync' ? 'loading' : undefined}
        >
          {'Sync All'}
        </Button>
      </ButtonGroup>
      {list}
    </>
  );
}

const settingsGroups = [
  {
    title: 'Sync',
    items: [
      {
        key: 'enable_autosync',
        title: 'Enable Autosync',
        description: 'Automatically sync Cloudflare data when supported sync actions run.',
      },
      {
        key: 'validate_token_before_sync',
        title: 'Validate token before sync',
        description: 'Check token status before Cloudflare sync operations.',
      },
      {
        key: 'log_api_requests',
        title: 'Log Cloudflare API calls',
        description: 'Store request and response details in API Logs.',
      },
    ],
  },
  {
    title: 'Domain cleanup',
    items: [
      {
        key: 'remove_records_on_domain_delete',
        title: 'Remove records automatically on domain delete',
        description: 'Delete matching Cloudflare DNS records when a Plesk domain is removed.',
        intent: 'warning',
      },
    ],
  },
  {
    title: 'Proxy defaults',
    items: [
      {
        key: 'proxy_a',
        title: 'Enable proxy for A records',
        description: 'Create or sync A records with Cloudflare proxy enabled by default.',
      },
      {
        key: 'proxy_aaaa',
        title: 'Enable proxy for AAAA records',
        description: 'Create or sync AAAA records with Cloudflare proxy enabled by default.',
      },
      {
        key: 'proxy_cname',
        title: 'Enable proxy for CNAME records',
        description: 'Create or sync CNAME records with Cloudflare proxy enabled by default.',
      },
    ],
  },
];

function TokenApp({ actions, initialTokens }) {
  const [tokens, setTokens] = useState(initialTokens);
  const [toasts, setToasts] = useState([]);
  const [addOpen, setAddOpen] = useState(false);
  const [editToken, setEditToken] = useState(null);
  const [changed, setChanged] = useState(false);
  const [busy, setBusy] = useState('');
  const listTarget = document.getElementById('gc-token-list');

  const notify = (intent, message) => {
    const key = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    setToasts(current => [
      { key, intent, message, autoClosable: intent === 'success', closable: true },
      ...current,
    ].slice(0, 5));
  };

  const run = async (label, task, successMessage = '') => {
    setBusy(label);
    try {
      const payload = await task();
      if (payload.tokens) {
        setTokens(payload.tokens);
      }
      if (successMessage || payload.message) {
        notify('success', successMessage || payload.message);
      }
      return payload;
    } catch (error) {
      if (error.payload?.tokens) {
        setTokens(error.payload.tokens);
      }
      notify('danger', error.message);
      return null;
    } finally {
      setBusy('');
    }
  };

  const addToken = values => {
    const name = String(values.name || '').trim();
    const token = String(values.token || '').trim();
    if (!name || !token) {
      notify('danger', !name ? 'Token name is required.' : 'API token is required.');
      return;
    }

    run(
      'add',
      () => postAction(actions.add, { name, token }),
      'Token added successfully.'
    ).then(payload => {
      if (payload) {
        setChanged(false);
        setAddOpen(false);
      }
    });
  };

  const updateToken = values => {
    const name = String(values.name || '').trim();
    const token = String(values.token || '').trim();
    if (!editToken || !name) {
      notify('danger', 'Token name is required.');
      return;
    }

    run(
      `edit-${editToken.id}`,
      () => postAction(actions.update, { id: editToken.id, name, token }),
      'Token updated successfully.'
    ).then(payload => {
      if (payload) {
        setChanged(false);
        setEditToken(null);
      }
    });
  };

  const validateToken = token => {
    run(
      `validate-${token.id}`,
      () => postAction(actions.validate, { id: token.id }),
      'Token validated successfully.'
    );
  };

  const deleteToken = token => {
    if (!window.confirm(`Delete token "${token.name}"?`)) {
      return;
    }

    run(
      `delete-${token.id}`,
      () => postAction(actions.delete, { id: token.id }),
      'Token deleted successfully.'
    );
  };

  const columns = [
    {
      key: 'name',
      title: 'Name',
      truncate: true,
      type: 'title',
      width: '34%',
      render: row => <Link component="span">{row.name}</Link>,
    },
    {
      key: 'status',
      title: 'Status',
      type: 'controls',
      width: '14%',
      render: row => {
        const status = statusProps(row.status);
        return (
          <Status intent={status.intent} compact>
            {status.label}
          </Status>
        );
      },
    },
    {
      key: 'last_verified_at',
      title: 'Last validated',
      width: '18%',
      render: row => row.last_verified_at || '-',
    },
    {
      key: 'created_at',
      title: 'Created',
      width: '18%',
    },
    {
      key: 'actions',
      type: 'actions',
      width: '16%',
      render: row => (
        <div className="gc-token-actions">
          <Button icon="pencil" ghost onClick={() => setEditToken(row)}>
            {'Edit'}
          </Button>
          <Button
            icon="reload"
            ghost
            onClick={() => validateToken(row)}
            state={busy === `validate-${row.id}` ? 'loading' : undefined}
          >
            {'Validate'}
          </Button>
          <Button
            icon={<Icon name="recycle" intent="danger" />}
            ghost
            onClick={() => deleteToken(row)}
            state={busy === `delete-${row.id}` ? 'loading' : undefined}
          >
            {'Delete'}
          </Button>
        </div>
      ),
    },
  ];

  const data = tokens.map(token => ({
    ...token,
    key: String(token.id),
  }));

  return (
    <>
      <Toaster
        toasts={toasts}
        position="top-end"
        onToastClose={key => setToasts(current => current.filter(toast => toast.key !== key))}
      />
      <Button arrow="forward" intent="primary" onClick={() => setAddOpen(true)}>
        {'Add Token'}
      </Button>

      {listTarget &&
        createPortal(
          data.length ? (
            <List columns={columns} data={data} />
          ) : (
            <EmptyList
              title="No tokens added yet"
              description="Use Add Token to connect a Cloudflare API token."
            />
          ),
          listTarget
        )}

      <Drawer
        title="Credentials"
        isOpen={addOpen}
        size="xs"
        className="gc-token-drawer"
        closingConfirmation={changed}
        form={{
          applyButton: false,
          submitButton: false,
          onSubmit: addToken,
          onFieldChange: () => setChanged(true),
          values: {
            name: '',
            token: '',
          },
        }}
        onClose={() => setAddOpen(false)}
        data-type="cloudflare-token"
      >
        <CredentialsHelp />
        <FormFieldText
          name="name"
          label="Token name"
          autoComplete="off"
          size="lg"
          vertical
          required
        />
        <FormFieldPassword
          name="token"
          label="API token"
          autoComplete="new-password"
          hideCopyButton
          hideGenerateButton
          hidePasswordMeter
          size="lg"
          vertical
          required
        />
        <div className="gc-drawer-actions">
          <Button type="submit" intent="primary" state={busy === 'add' ? 'loading' : undefined}>
            {'Add Token'}
          </Button>
          <Button type="button" onClick={() => setAddOpen(false)}>
            {'Cancel'}
          </Button>
        </div>
      </Drawer>

      <Drawer
        title="Edit Token"
        isOpen={!!editToken}
        size="xs"
        closingConfirmation={changed}
        form={{
          applyButton: false,
          submitButton: false,
          onSubmit: updateToken,
          onFieldChange: () => setChanged(true),
          values: {
            name: editToken?.name || '',
            token: '',
          },
        }}
        onClose={() => setEditToken(null)}
        key={editToken?.id || 'edit-token'}
      >
        <FormFieldText
          name="name"
          label="Token name"
          autoComplete="off"
          size="lg"
          vertical
          required
        />
        <FormFieldPassword
          name="token"
          label="New API token"
          autoComplete="new-password"
          hideCopyButton
          hideGenerateButton
          hidePasswordMeter
          size="lg"
          vertical
        />
        <div className="gc-drawer-note">
          Leave token blank to keep the existing API token.
        </div>
        <div className="gc-drawer-actions">
          <Button
            type="submit"
            intent="primary"
            state={editToken && busy === `edit-${editToken.id}` ? 'loading' : undefined}
          >
            {'Save'}
          </Button>
          <Button type="button" onClick={() => setEditToken(null)}>
            {'Cancel'}
          </Button>
        </div>
      </Drawer>
    </>
  );
}

function LogsApp({ clearAction, initialLogs }) {
  const [logs, setLogs] = useState(initialLogs);
  const [toasts, setToasts] = useState([]);
  const [busy, setBusy] = useState('');
  const [selectedLog, setSelectedLog] = useState(null);
  const [page, setPage] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(10);
  const [searchQuery, setSearchQuery] = useState('');
  const listTarget = document.getElementById('gc-api-log-list');

  const notify = (intent, message) => {
    const key = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    setToasts(current => [
      { key, intent, message, autoClosable: intent === 'success', closable: true },
      ...current,
    ].slice(0, 5));
  };

  const clearLogs = async () => {
    setBusy('clear');
    try {
      const payload = await postAction(clearAction, {});
      setLogs(payload.apiLogs || []);
      notify('success', payload.message || 'API logs removed successfully.');
      setPage(1);
    } catch (error) {
      notify('danger', error.message);
    } finally {
      setBusy('');
    }
  };

  const copyLog = async log => {
    await copyToClipboard(JSON.stringify({ request: log.request, response: log.response }, null, 2));
    notify('success', 'Request and response copied.');
  };

  const rows = logs
    .filter(log => matchesSearch(log, searchQuery, [
      'created_at',
      'route',
      'method',
      'status_code',
      'duration_ms',
      'error',
      log => JSON.stringify(log.request ?? ''),
      log => JSON.stringify(log.response ?? ''),
    ]))
    .map(log => ({
      ...log,
      key: String(log.id),
    }));
  const totalPages = itemsPerPage === 'all' ? 1 : Math.max(1, Math.ceil(rows.length / itemsPerPage));
  const currentPage = Math.min(page, totalPages);
  const visibleRows = itemsPerPage === 'all'
    ? rows
    : rows.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);

  useEffect(() => {
    if (page > totalPages) {
      setPage(totalPages);
    }
  }, [page, totalPages]);

  const columns = [
    {
      key: 'created_at',
      title: 'Time',
      width: '17%',
    },
    {
      key: 'route',
      title: 'Route',
      width: '28%',
      truncate: true,
      render: row => <code>{row.route}</code>,
    },
    {
      key: 'method',
      title: 'Method',
      width: '10%',
    },
    {
      key: 'status_code',
      title: 'Status',
      width: '12%',
      render: row => (
        <Status intent={row.ok ? 'success' : 'danger'} compact>
          {row.status_code || '-'}
        </Status>
      ),
    },
    {
      key: 'duration_ms',
      title: 'Duration',
      width: '11%',
      render: row => (row.duration_ms === null || row.duration_ms === undefined ? '-' : `${row.duration_ms} ms`),
    },
    {
      key: 'actions',
      type: 'actions',
      width: '22%',
      render: row => (
        <div className="gc-api-log-actions">
          <Button
            icon="eye"
            ghost
            onClick={() => setSelectedLog(row)}
            title="View"
            aria-label="View"
          />
          <Button
            icon={<Icon name="copy" />}
            ghost
            onClick={() => copyLog(row)}
            title="Copy"
            aria-label="Copy"
          />
        </div>
      ),
    },
  ];

  return (
    <>
      <Toaster
        toasts={toasts}
        position="top-end"
        onToastClose={key => setToasts(current => current.filter(toast => toast.key !== key))}
      />
      {!!logs.length && (
        <>
          <div className="gc-list-toolbar">
            <SearchBar
              inputProps={{
                value: searchQuery,
                placeholder: 'Search API logs',
              }}
              onTyping={value => {
                setSearchQuery(value);
                setPage(1);
              }}
              onSearch={value => {
                setSearchQuery(value);
                setPage(1);
              }}
            />
          </div>
          <Button
            arrow="forward"
            intent="primary"
            onClick={clearLogs}
            state={busy === 'clear' ? 'loading' : undefined}
          >
            {'Remove Logs'}
          </Button>
        </>
      )}
      {listTarget &&
        createPortal(
          <div className="gc-api-log-shell">
            <List
              columns={columns}
              data={visibleRows}
              rowKey="key"
              className="gc-api-log-list"
              pagination={rows.length ? (
                <Pagination
                  total={totalPages}
                  current={currentPage}
                  onSelect={setPage}
                  itemsPerPage={itemsPerPage}
                  itemsPerPageOptions={[10, 25, 50, 100, 'all']}
                  onItemsPerPageChange={value => {
                    setItemsPerPage(value);
                    setPage(1);
                  }}
                />
              ) : undefined}
              emptyView={
                <ListEmptyView
                  title={logs.length ? 'No matching API logs' : 'No API calls logged yet'}
                  description={logs.length
                    ? 'Try a different search query.'
                    : 'Cloudflare API calls will appear here after token validation or sync actions.'}
                />
              }
            />
          </div>,
          listTarget
        )}
      <LogDetailsDrawer
        log={selectedLog}
        onClose={() => setSelectedLog(null)}
        notify={notify}
      />
    </>
  );
}

function LogDetailsDrawer({ log, onClose, notify }) {
  if (!log) {
    return null;
  }

  const copy = async (label, value) => {
    await copyToClipboard(typeof value === 'string' ? value : JSON.stringify(value ?? null, null, 2));
    notify('success', `${label} copied.`);
  };

  const fullLog = {
    route: log.route,
    method: log.method,
    status: log.status_code,
    ok: log.ok,
    durationMs: log.duration_ms,
    createdAt: log.created_at,
    request: log.request,
    response: log.response,
    error: log.error,
  };

  return (
    <Drawer
      isOpen
      size="md"
      title="API Call Details"
      onClose={onClose}
      className="gc-api-log-drawer"
    >
      <div className="gc-log-summary">
        <div><span>Route</span><code>{log.route}</code></div>
        <div><span>Method</span><code>{log.method}</code></div>
        <div><span>Status</span><code>{log.status_code || '-'}</code></div>
        <div><span>Duration</span><code>{log.duration_ms === null || log.duration_ms === undefined ? '-' : `${log.duration_ms} ms`}</code></div>
        <div><span>Time</span><code>{log.created_at}</code></div>
      </div>
      <div className="gc-log-copy-row">
        <Button icon="copy" onClick={() => copy('Full log', fullLog)}>
          {'Copy Full Log'}
        </Button>
      </div>
      <LogJsonBlock title="Request" value={log.request} onCopy={() => copy('Request', log.request)} />
      <LogJsonBlock title="Response" value={log.response} onCopy={() => copy('Response', log.response)} />
      {log.error ? <LogJsonBlock title="Error" value={log.error} onCopy={() => copy('Error', log.error)} /> : null}
    </Drawer>
  );
}

function LogJsonBlock({ title, value, onCopy }) {
  return (
    <section className="gc-log-json-block">
      <div className="gc-log-json-title">
        <h3>{title}</h3>
        <Button icon="copy" ghost onClick={onCopy}>
          {'Copy'}
        </Button>
      </div>
      <pre>{JSON.stringify(value ?? null, null, 2)}</pre>
    </section>
  );
}

async function copyToClipboard(text) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }

  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', 'readonly');
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.select();
  document.execCommand('copy');
  textarea.remove();
}

function CredentialsHelp() {
  return (
    <div className="gc-credentials-help">
      <p>To create your API token:</p>
      <ol>
        <li>
          Log in to your account using the{' '}
          <a
            href="https://dash.cloudflare.com/profile/api-tokens"
            target="_blank"
            rel="noopener noreferrer"
          >
            Cloudflare
          </a>
          .
        </li>
        <li>
          Go to <strong>My Profile &gt; API Tokens</strong>.
        </li>
        <li>
          Click <strong>Create Token</strong>, and then click{' '}
          <strong>Create Custom Token</strong>.
        </li>
        <li>
          Specify the <strong>"Zone:Zone:Edit"</strong> and{' '}
          <strong>"Zone:DNS:Edit"</strong> permissions.
        </li>
      </ol>
    </div>
  );
}

function SettingsApp({ saveAction, initialSettings }) {
  const [settings, setSettings] = useState(initialSettings);
  const [toasts, setToasts] = useState([]);
  const [busyKey, setBusyKey] = useState('');
  const panelTarget = document.getElementById('gc-settings-panel');

  const notify = (intent, message) => {
    const key = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    setToasts(current => [
      { key, intent, message, autoClosable: intent === 'success', closable: true },
      ...current,
    ].slice(0, 5));
  };

  const updateSetting = async (key, value) => {
    const previous = settings;
    const next = {
      ...settings,
      [key]: Boolean(value),
    };

    setSettings(next);
    setBusyKey(key);
    try {
      const payload = await postAction(saveAction, settingsPayload(next));
      if (payload.settings) {
        setSettings(payload.settings);
      }
      notify('success', 'Setting updated.');
    } catch (error) {
      setSettings(previous);
      notify('danger', error.message);
    } finally {
      setBusyKey('');
    }
  };

  const columns = [
    {
      key: 'title',
      title: 'Setting',
      type: 'title',
      width: '34%',
      render: row => (
        <div className="gc-setting-title">
          <span>{row.title}</span>
          {row.intent === 'warning' ? (
            <Status intent="warning" compact>
              {'Careful'}
            </Status>
          ) : null}
        </div>
      ),
    },
    {
      key: 'description',
      title: 'Description',
      width: '54%',
      render: row => <span>{row.description}</span>,
    },
    {
      key: 'enabled',
      title: 'Enabled',
      type: 'controls',
      width: '12%',
      render: row => (
        <Switch
          checked={Boolean(settings[row.key])}
          loading={busyKey === row.key}
          onChange={value => updateSetting(row.key, value)}
          aria-label={row.title}
        />
      ),
    },
  ];

  return (
    <>
      <Toaster
        toasts={toasts}
        position="top-end"
        onToastClose={key => setToasts(current => current.filter(toast => toast.key !== key))}
      />
      {panelTarget &&
        createPortal(
          <div className="gc-settings-shell">
            {settingsGroups.map(group => {
              const enabledCount = group.items.filter(item => settings[item.key]).length;
              const data = group.items.map(item => ({
                ...item,
                group: group.title,
                key: item.key,
              }));

              return (
                <section className="gc-settings-section" key={group.title}>
                  <div className="gc-settings-section-header">
                    <div>
                      <h3>{group.title}</h3>
                      <p>{enabledCount} of {group.items.length} enabled</p>
                    </div>
                    <Status intent={enabledCount === group.items.length ? 'success' : 'info'} compact>
                      {enabledCount === group.items.length ? 'All enabled' : 'Custom'}
                    </Status>
                  </div>
                  <List columns={columns} data={data} rowKey="key" />
                </section>
              );
            })}
          </div>,
          panelTarget
        )}
    </>
  );
}

function AboutApp({ info, logo }) {
  const target = document.getElementById('gc-about-panel');
  const version = info.version || '1.0.0';
  const highlights = [
    {
      icon: 'cloud',
      title: 'Cloudflare DNS sync',
      description: 'Import, export, and sync Plesk DNS records with linked Cloudflare zones.',
    },
    {
      icon: 'lock-closed',
      title: 'Per-user access',
      description: 'Tokens, settings, domains, jobs, and API logs stay scoped to the logged-in Plesk user.',
    },
    {
      icon: 'reload',
      title: 'Autosync jobs',
      description: 'Event-based DNS pushes and long-running sync jobs keep updates moving without request timeouts.',
    },
    {
      icon: 'list',
      title: 'API visibility',
      description: 'Cloudflare API requests are logged with route, status, duration, request, and response details.',
    },
  ];

  if (!target) {
    return null;
  }

  return createPortal(
    <div className="gc-about">
      <section className="gc-about-hero">
        <div className="gc-about-brand">
          <img src={logo} alt="Cloudflare" className="gc-about-logo" />
          <div>
            <Status intent="warning" compact>
              {'Pre-release'}
            </Status>
            <h2>{info.name || 'Cloudflare Pro'}</h2>
            <p>{info.description}</p>
          </div>
        </div>
        <div className="gc-about-version">
          <span>{'Version'}</span>
          <strong>{version}</strong>
        </div>
      </section>

      <section className="gc-about-grid" aria-label="Cloudflare Pro features">
        {highlights.map(item => (
          <article className="gc-about-card" key={item.title}>
            <div className="gc-about-card-icon">
              <Icon name={item.icon} />
            </div>
            <h3>{item.title}</h3>
            <p>{item.description}</p>
          </article>
        ))}
      </section>

      <section className="gc-about-developer">
        <div className="gc-about-developer-logo">
          <img src={info.developerLogo || 'https://assets.ghostcompiler.in/logo.png'} alt="Ghost Compiler" />
        </div>
        <div className="gc-about-developer-copy">
          <Status intent="info" compact>
            {'Developer'}
          </Status>
          <h3>{info.brand || 'Ghost Compiler'}</h3>
          <p>{'Designed and developed by Ghost Compiler for Cloudflare DNS management inside Plesk.'}</p>
        </div>
        <div className="gc-about-developer-meta">
          <div>
            <span>{'Extension'}</span>
            <strong>{info.name || 'Cloudflare Pro'}</strong>
          </div>
          <div>
            <span>{'Version'}</span>
            <strong>{version}</strong>
          </div>
          <div>
            <span>{'Website'}</span>
            <Link href={info.vendorUrl || 'https://ghostcompiler.com'} target="_blank">
              {'ghostcompiler.com'}
            </Link>
          </div>
          <div>
            <span>{'GitHub'}</span>
            <Link href={info.githubUrl || 'https://github.com/ghostcompiler'} target="_blank">
              {'github.com/ghostcompiler'}
            </Link>
          </div>
          <div>
            <span>{'Repository'}</span>
            <Link href={info.repositoryUrl || 'https://github.com/ghostcompiler/cloudflare-pro'} target="_blank">
              {'ghostcompiler/cloudflare-pro'}
            </Link>
          </div>
        </div>
      </section>
    </div>,
    target
  );
}

function settingsPayload(settings) {
  return Object.fromEntries(
    Object.entries(settings).map(([key, value]) => [key, value ? '1' : '0'])
  );
}

function parseTokens(value) {
  try {
    const data = JSON.parse(value || '[]');
    return Array.isArray(data) ? data : [];
  } catch (error) {
    return [];
  }
}

function parseSettings(value) {
  try {
    const data = JSON.parse(value || '{}');
    return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
  } catch (error) {
    return {};
  }
}

function parseLogs(value) {
  try {
    const data = JSON.parse(value || '[]');
    return Array.isArray(data) ? data : [];
  } catch (error) {
    return [];
  }
}

function parseDomains(value) {
  try {
    const data = JSON.parse(value || '[]');
    return Array.isArray(data) ? data : [];
  } catch (error) {
    return [];
  }
}

function parseRecords(value) {
  try {
    const data = JSON.parse(value || '[]');
    return Array.isArray(data) ? data : [];
  } catch (error) {
    return [];
  }
}

function parseInfo(value) {
  try {
    const data = JSON.parse(value || '{}');
    return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
  } catch (error) {
    return {};
  }
}

function EmptyApp({ title, description }) {
  const target = document.getElementById('gc-empty-list');

  if (!target) {
    return null;
  }

  return createPortal(
    <EmptyList title={title} description={description} />,
    target
  );
}

const domainRootElement = document.getElementById('gc-domain-app');

if (domainRootElement) {
  createRoot(domainRootElement).render(
    <DomainApp
      syncAction={domainRootElement.dataset.syncDomainAction}
      autosyncAction={domainRootElement.dataset.autosyncAction}
      recordsAction={domainRootElement.dataset.recordsAction}
      initialDomains={parseDomains(domainRootElement.dataset.domains)}
    />
  );
}

const recordsRootElement = document.getElementById('gc-records-app');

if (recordsRootElement) {
  createRoot(recordsRootElement).render(
    <RecordsApp
      proxyAction={recordsRootElement.dataset.proxyAction}
      syncAction={recordsRootElement.dataset.syncAction}
      startSyncJobAction={recordsRootElement.dataset.startSyncJobAction}
      processSyncJobAction={recordsRootElement.dataset.processSyncJobAction}
      syncJobStatusAction={recordsRootElement.dataset.syncJobStatusAction}
      recordAction={recordsRootElement.dataset.recordAction}
      domain={parseSettings(recordsRootElement.dataset.domain)}
      initialRecords={parseRecords(recordsRootElement.dataset.records)}
    />
  );
}

const rootElement = document.getElementById('gc-token-app');

if (rootElement) {
  createRoot(rootElement).render(
    <TokenApp
      actions={{
        add: rootElement.dataset.addTokenAction,
        update: rootElement.dataset.updateTokenAction,
        validate: rootElement.dataset.validateTokenAction,
        delete: rootElement.dataset.deleteTokenAction,
      }}
      initialTokens={parseTokens(rootElement.dataset.tokens)}
    />
  );
}

const logRootElement = document.getElementById('gc-log-app');

if (logRootElement) {
  createRoot(logRootElement).render(
    <LogsApp
      clearAction={logRootElement.dataset.clearLogsAction}
      initialLogs={parseLogs(logRootElement.dataset.logs)}
    />
  );
}

const emptyRootElement = document.getElementById('gc-empty-app');

if (emptyRootElement) {
  createRoot(emptyRootElement).render(
    <EmptyApp
      title={emptyRootElement.dataset.emptyTitle}
      description={emptyRootElement.dataset.emptyDescription}
    />
  );
}

const settingsRootElement = document.getElementById('gc-settings-app');

if (settingsRootElement) {
  createRoot(settingsRootElement).render(
    <SettingsApp
      saveAction={settingsRootElement.dataset.saveSettingsAction}
      initialSettings={parseSettings(settingsRootElement.dataset.settings)}
    />
  );
}

const aboutRootElement = document.getElementById('gc-about-app');

if (aboutRootElement) {
  createRoot(aboutRootElement).render(
    <AboutApp
      info={parseInfo(aboutRootElement.dataset.info)}
      logo={aboutRootElement.dataset.logo}
    />
  );
}
