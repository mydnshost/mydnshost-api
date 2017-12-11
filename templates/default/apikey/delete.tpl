{% autoescape false %}
{% block subject %}
API Key Deleted: {{ apikey.getDescription() }}
{% endblock %}

{% block body %}
Hi {{ user.getRealName() }},

An API Key has been removed from your account on {{ sitename }}.

    Key: {{ apikey.getKey() }}
    Description: {{ apikey.getDescription() }}
    Permissions:
        Domain Read: {{ apikey.getDomainRead() | yesno }}
        Domain Write: {{ apikey.getDomainWrite() | yesno }}
        User Read: {{ apikey.getUserRead() | yesno }}
        User Write:  {{ apikey.getUserWrite() | yesno }}

{% include 'footer.txt' %}
{% endblock %}
{% endautoescape %}
